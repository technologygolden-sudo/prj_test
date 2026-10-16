<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vérifie les règles de gestion portées par la base (dossier de conception v2, section 2.2).
 */
class ContraintesBaseTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = User::factory()->create()->id;
    }

    public function test_le_seeder_cree_le_catalogue_et_les_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(PermissionSeeder::CATALOGUE), Permission::count());
        $this->assertSame(6, Role::count());

        $admin = Role::findByName(PermissionSeeder::ADMINISTRATEUR);
        $this->assertTrue((bool) $admin->systeme);
        $this->assertSame(count(PermissionSeeder::CATALOGUE), $admin->permissions()->count());
        $this->assertFalse(Role::findByName('Lecteur')->hasPermissionTo('articles.gerer'));
        $this->assertTrue((bool) Permission::findByName('assemblage.annuler')->sensible);
    }

    public function test_rg07_numero_de_serie_unique_par_fabricant(): void
    {
        $fab1 = $this->fabricant('Huawei');
        $fab2 = $this->fabricant('SMA');
        $art1 = $this->article('OND-1', true, $fab1);
        $art2 = $this->article('OND-2', true, $fab2);

        $this->unite($art1, 'SN-001');
        $this->unite($art2, 'SN-001'); // autre fabricant : accepté

        $this->assertRefus(fn () => $this->unite($art1, 'SN-001'));
        $this->assertSame($fab1, DB::table('unite_article')->where('article_id', $art1)->value('fabricant_id'));
    }

    public function test_rg04_la_nature_suit_l_article(): void
    {
        $fab = $this->fabricant('Huawei');
        $serialise = $this->article('OND-1', true, $fab);
        $lot = $this->article('VIS-1', false, $fab);

        $this->assertRefus(fn () => $this->unite($serialise, null, 'lot', 10));
        $this->assertRefus(fn () => $this->unite($lot, 'SN-9'));
    }

    public function test_rg05_le_porteur_doit_etre_serialise_et_en_quantite_1(): void
    {
        $fab = $this->fabricant('Huawei');
        $onduleur = $this->article('OND-1', true, $fab);
        $panneau = $this->article('PAN-1', false, $fab);

        $this->assertRefus(function () use ($onduleur) {
            $this->version($onduleur, [$onduleur => 2]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertRefus(function () use ($panneau) {
            $this->version($panneau, [$panneau => 1]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertRefus(function () use ($onduleur, $panneau) {
            $this->version($onduleur, [$panneau => 4]); // porteur absent de la composition
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });

        DB::transaction(function () use ($onduleur, $panneau) {
            $this->version($onduleur, [$onduleur => 1, $panneau => 4]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertSame(1, DB::table('version_kit')->count());
    }

    public function test_rg10_une_version_verrouillee_est_figee(): void
    {
        $fab = $this->fabricant('Huawei');
        $onduleur = $this->article('OND-1', true, $fab);
        $panneau = $this->article('PAN-1', false, $fab);
        $version = $this->version($onduleur, [$onduleur => 1, $panneau => 4]);

        DB::table('version_kit')->where('id', $version)->update(['verrouillee' => true, 'verrouillee_le' => now()]);

        $this->assertRefus(fn () => DB::table('composition')
            ->where(['version_kit_id' => $version, 'article_id' => $panneau])->update(['quantite' => 5]));
        $this->assertRefus(fn () => DB::table('version_kit')->where('id', $version)->update(['verrouillee' => false]));
        $this->assertRefus(fn () => DB::table('version_kit')->where('id', $version)->delete());
    }

    public function test_rg06_rg09_rg25_kit_et_porteur(): void
    {
        $fab = $this->fabricant('Huawei');
        $onduleur = $this->article('OND-1', true, $fab);
        $version = $this->version($onduleur, [$onduleur => 1]);
        $porteur = $this->unite($onduleur, 'SN-777');

        $kit = $this->kit($version, $porteur, 'K-1');
        $this->assertSame('SN-777', DB::table('kit_assemble')->where('id', $kit)->value('numero_serie'));

        $this->assertRefus(fn () => $this->kit($version, $porteur, 'K-2'));

        $this->assertRefus(fn () => DB::table('kit_assemble')->where('id', $kit)->update(['statut' => 'desassemble']));
        DB::table('kit_assemble')->where('id', $kit)->update([
            'statut' => 'desassemble', 'desassemble_le' => now(), 'motif_desassemblage' => 'Composant défectueux',
        ]);

        $this->kit($version, $porteur, 'K-3'); // réassemblage accepté
        $this->assertSame(2, DB::table('kit_assemble')->where('unite_porteur_id', $porteur)->count());
    }

    public function test_rg24_rg27_mouvements_de_stock(): void
    {
        $fab = $this->fabricant('Huawei');
        $vis = $this->article('VIS-1', false, $fab);
        $lot = $this->unite($vis, null, 'lot', 100);

        $this->assertRefus(fn () => $this->mouvement($lot, 'ajustement', -3, null));
        $this->mouvement($lot, 'ajustement', -3, 'Écart inventaire');
        $this->assertRefus(fn () => $this->mouvement($lot, 'entree', 0, null));
        $this->assertRefus(fn () => DB::table('unite_article')->where('id', $lot)->update(['quantite_restante' => 101]));
    }

    public function test_enf05_le_journal_d_audit_est_en_ajout_seul(): void
    {
        $id = DB::table('journal_audit')->insertGetId([
            'utilisateur_id' => $this->userId, 'ip' => '10.0.0.5', 'action' => 'creer', 'objet_type' => 'article', 'objet_id' => '1',
        ]);

        $this->assertRefus(fn () => DB::table('journal_audit')->where('id', $id)->update(['action' => 'autre']));
        $this->assertRefus(fn () => DB::table('journal_audit')->where('id', $id)->delete());
    }

    public function test_rg12_rg15_reimpression_motivee_et_parametres_uniques(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertRefus(fn () => DB::table('parametre_societe')->insert(['id' => 2, 'nom_societe' => 'X', 'num_sav' => '0']));

        $fab = $this->fabricant('Huawei');
        $unite = $this->unite($this->article('OND-1', true, $fab), 'SN-1');
        $imprimante = DB::table('imprimante')->insertGetId(['nom' => 'ZD411 magasin', 'adresse' => '10.0.0.50:9100', 'modele' => 'ZD411']);
        $modele = DB::table('modele_etiquette')->insertGetId([
            'code' => 'article-50x30', 'cible' => 'article', 'format' => 'ZPL', 'largeur_mm' => 50, 'hauteur_mm' => 30, 'gabarit' => '^XA^XZ',
        ]);
        $ligne = fn (array $extra) => DB::table('impression_etiquette')->insert($extra + [
            'unite_id' => $unite, 'modele_id' => $modele, 'imprimante_id' => $imprimante, 'utilisateur_id' => $this->userId,
        ]);

        $ligne([]);
        $this->assertRefus(fn () => $ligne(['est_reimpression' => true]));
        $ligne(['est_reimpression' => true, 'motif' => 'Étiquette abîmée']);
    }

    private function assertRefus(callable $operation): void
    {
        try {
            DB::transaction($operation);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("L'opération aurait dû être refusée par la base.");
    }

    private function fabricant(string $nom): int
    {
        return DB::table('fabricant')->insertGetId(['nom' => $nom]);
    }

    private function article(string $reference, bool $serialise, int $fabricant): int
    {
        return DB::table('article')->insertGetId([
            'reference' => $reference, 'nom' => $reference, 'est_serialise' => $serialise, 'fabricant_id' => $fabricant,
        ]);
    }

    /** @param array<int, int> $composition article_id => quantité */
    private function version(int $porteur, array $composition): int
    {
        static $n = 0;
        $n++;
        DB::statement('SET CONSTRAINTS ALL DEFERRED'); // le porteur est inséré dans la composition après la version
        $type = DB::table('type_kit')->insertGetId(['reference' => "KIT-$n", 'partno' => "PN-$n", 'nom' => "Kit $n"]);
        $version = DB::table('version_kit')->insertGetId(['type_kit_id' => $type, 'numero' => 1, 'article_porteur_id' => $porteur]);
        foreach ($composition as $article => $quantite) {
            DB::table('composition')->insert(['version_kit_id' => $version, 'article_id' => $article, 'quantite' => $quantite]);
        }

        return $version;
    }

    private function unite(int $article, ?string $serie, string $nature = 'serie', int $quantite = 1): int
    {
        static $n = 0;
        $n++;

        return DB::table('unite_article')->insertGetId([
            'uid' => "A-TEST$n", 'nature' => $nature, 'numero_serie' => $serie,
            'quantite_initiale' => $quantite, 'quantite_restante' => $quantite, 'article_id' => $article,
        ]);
    }

    private function kit(int $version, int $porteur, string $uid): int
    {
        return DB::table('kit_assemble')->insertGetId([
            'uid' => $uid, 'numero_serie' => '', 'assemble_le' => now(), 'version_kit_id' => $version,
            'unite_porteur_id' => $porteur, 'utilisateur_id' => $this->userId,
        ]);
    }

    private function mouvement(int $unite, string $type, int $quantite, ?string $motif): void
    {
        DB::table('mouvement_stock')->insert([
            'type' => $type, 'quantite' => $quantite, 'unite_id' => $unite, 'motif' => $motif, 'utilisateur_id' => $this->userId,
        ]);
    }
}
