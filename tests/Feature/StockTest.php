<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Parcours magasin et atelier : réception, étiquettes, assemblage, désassemblage, sorties, ajustements. */
class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['securite.mfa_obligatoire' => false]);
        Storage::fake('local');
        $this->seed(DemoSeeder::class);
        $this->admin = User::where('login', 'admin.demo')->firstOrFail();
    }

    private function article(string $reference): object
    {
        return DB::table('article')->where('reference', $reference)->first();
    }

    private function versionKitA(): int
    {
        return DB::table('version_kit')->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->where('type_kit.reference', 'KIT-A')->value('version_kit.id');
    }

    public function test_reception_controle_les_numeros_de_serie(): void
    {
        $onduleur = $this->article('OND-5K');
        $base = ['date' => now()->toDateString(), 'num_bl' => 'BL-1', 'fournisseur' => 'Huawei', 'espace' => 'TEVIA'];

        // RG26 : nombre de n° différent de la quantité.
        $this->actingAs($this->admin)->post('/receptions', $base + ['lignes' => [
            ['article_id' => $onduleur->id, 'quantite' => 2, 'series' => ['N-1']],
        ]])->assertSessionHasErrors('lignes.0.series');

        // RG07 : n° déjà en base pour ce fabricant.
        $this->actingAs($this->admin)->post('/receptions', $base + ['lignes' => [
            ['article_id' => $onduleur->id, 'quantite' => 1, 'series' => ['OND-DEMO-001']],
        ]])->assertSessionHasErrors('lignes.0.series');

        $this->actingAs($this->admin)->post('/receptions', $base + ['lignes' => [
            ['article_id' => $onduleur->id, 'quantite' => 2, 'series' => ['N-1', 'N-2']],
            ['article_id' => $this->article('PAN-400')->id, 'quantite' => 12],
        ]])->assertRedirect();

        $reception = DB::table('reception')->where('num_bl', 'BL-1')->first();
        $unites = DB::table('unite_article')->where('reception_id', $reception->id)->get();
        $this->assertCount(3, $unites);
        $this->assertSame(['L', 'A', 'A'], $unites->sortBy('nature')->map(fn ($u) => $u->uid[0])->values()->all());
        $this->assertSame(14, (int) DB::table('mouvement_stock')->where('reception_id', $reception->id)->sum('quantite'));
    }

    public function test_impression_zpl_puis_reimpression_motivee(): void
    {
        $unite = DB::table('unite_article')->where('numero_serie', 'OND-DEMO-001')->first();
        $zd411 = DB::table('imprimante')->where('adresse', 'like', 'fichier:%')->value('id');
        $demande = ['cibles' => [['type' => 'unite', 'id' => $unite->id]], 'imprimante_id' => $zd411];

        $this->actingAs($this->admin)->post('/etiquettes/imprimer', $demande)->assertSessionHas('succes');
        $fichiers = Storage::disk('local')->files('impressions');
        $this->assertCount(1, $fichiers);
        $zpl = Storage::disk('local')->get($fichiers[0]);
        $this->assertStringContainsString('GREENO', $zpl);
        $this->assertStringContainsString('^FDMA,OND_2D5K^FS', $zpl); // QR code = référence CRM
        $this->assertStringContainsString('^FDOND_2DDEMO_2D001^FS', $zpl); // code-barres = n° de série

        // RG12 : la réimpression exige un motif, et un lecteur ne peut pas imprimer.
        $this->actingAs($this->admin)->post('/etiquettes/imprimer', $demande)->assertSessionHasErrors('motif');
        $this->actingAs($this->admin)->post('/etiquettes/imprimer', $demande + ['motif' => 'Étiquette abîmée'])->assertSessionHas('succes');
        $this->assertSame(1, DB::table('impression_etiquette')->where('unite_id', $unite->id)->where('est_reimpression', true)->count());

        $lecteur = User::where('login', 'lecteur.demo')->first();
        $this->actingAs($lecteur)->post('/etiquettes/imprimer', $demande + ['motif' => 'x'])->assertForbidden();
    }

    public function test_impression_pdf_pour_imprimante_de_bureau(): void
    {
        $lot = DB::table('unite_article')->where('nature', 'lot')->first();
        $bureau = DB::table('imprimante')->where('adresse', 'navigateur')->value('id');

        $reponse = $this->actingAs($this->admin)->post('/etiquettes/imprimer', [
            'cibles' => [['type' => 'unite', 'id' => $lot->id]], 'imprimante_id' => $bureau, 'copies' => 2,
        ]);

        $reponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_assemblage_desassemblage_et_reassemblage(): void
    {
        $version = $this->versionKitA();
        $porteur = DB::table('unite_article')->where('numero_serie', 'OND-DEMO-001')->first();
        $panneaux = DB::table('unite_article')->where('article_id', $this->article('PAN-400')->id)->first();
        $coffrets = DB::table('unite_article')->where('article_id', $this->article('COF-AC')->id)->first();

        // Contrôle au scan : un n° de série retrouve son unité, un article hors nomenclature est refusé.
        $this->actingAs($this->admin)->postJson('/assemblage/verifier', ['version_id' => $version, 'code' => 'OND-DEMO-001'])
            ->assertOk()->assertJson(['unite_id' => $porteur->id]);
        $this->actingAs($this->admin)->postJson('/assemblage/verifier', ['version_id' => $version, 'code' => 'BAT-DEMO-001'])
            ->assertStatus(422);

        $composants = [
            ['unite_id' => $porteur->id, 'quantite' => 1],
            ['unite_id' => $panneaux->id, 'quantite' => 4],
            ['unite_id' => $coffrets->id, 'quantite' => 1],
        ];

        // RG11 : quantité incomplète refusée.
        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => [
            ['unite_id' => $porteur->id, 'quantite' => 1], ['unite_id' => $panneaux->id, 'quantite' => 3], ['unite_id' => $coffrets->id, 'quantite' => 1],
        ]])->assertSessionHasErrors('composants');

        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => $composants])->assertRedirect();
        $kit = DB::table('kit_assemble')->first();
        $this->assertSame('OND-DEMO-001', $kit->numero_serie); // RG06
        $this->assertSame('assemble', DB::table('unite_article')->where('id', $porteur->id)->value('statut')); // RG09
        $this->assertSame(26, DB::table('unite_article')->where('id', $panneaux->id)->value('quantite_restante'));

        // Le porteur ne peut pas servir deux fois.
        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => $composants])->assertSessionHasErrors('composants');

        // RG25 : désassemblage motivé, composants restitués, puis réassemblage possible.
        $this->actingAs($this->admin)->post("/kits-assembles/{$kit->id}/desassembler", ['motif' => 'Coffret défectueux'])->assertSessionHas('succes');
        $this->assertSame('en_stock', DB::table('unite_article')->where('id', $porteur->id)->value('statut'));
        $this->assertSame(30, DB::table('unite_article')->where('id', $panneaux->id)->value('quantite_restante'));
        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => $composants])->assertRedirect();
        $this->assertSame(2, DB::table('kit_assemble')->count());
    }

    public function test_sortie_et_ajustement(): void
    {
        $batterie = DB::table('unite_article')->where('numero_serie', 'BAT-DEMO-001')->first();
        $lot = DB::table('unite_article')->where('article_id', $this->article('COF-AC')->id)->first();

        $this->actingAs($this->admin)->postJson('/sorties/verifier', ['code' => 'BAT-DEMO-001'])->assertOk()->assertJson(['type' => 'unite', 'id' => $batterie->id]);

        $this->actingAs($this->admin)->post('/sorties', [
            'type' => 'rebut', 'lignes' => [
                ['type' => 'unite', 'id' => $batterie->id, 'quantite' => 1],
                ['type' => 'unite', 'id' => $lot->id, 'quantite' => 2],
            ],
        ])->assertRedirect('/sorties');

        $this->assertSame('rebute', DB::table('unite_article')->where('id', $batterie->id)->value('statut'));
        $this->assertSame(4, DB::table('unite_article')->where('id', $lot->id)->value('quantite_restante'));
        $this->assertSame('en_stock', DB::table('unite_article')->where('id', $lot->id)->value('statut'));

        // RG27 : ajustement motivé ; un lot ne descend pas sous zéro.
        $this->actingAs($this->admin)->post("/stock/{$lot->id}/ajuster", ['quantite' => -5, 'motif' => 'Inventaire annuel'])->assertSessionHasErrors('quantite');
        $this->actingAs($this->admin)->post("/stock/{$lot->id}/ajuster", ['quantite' => -4, 'motif' => 'Inventaire annuel'])->assertSessionHas('succes');
        $this->assertSame('epuise', DB::table('unite_article')->where('id', $lot->id)->value('statut'));

        $onduleur = DB::table('unite_article')->where('numero_serie', 'OND-DEMO-002')->first();
        $this->actingAs($this->admin)->post("/stock/{$onduleur->id}/ajuster", ['quantite' => -1, 'motif' => 'Introuvable à l’inventaire'])->assertSessionHas('succes');
        $this->assertSame('perdu', DB::table('unite_article')->where('id', $onduleur->id)->value('statut'));
    }

    public function test_recherche_par_scan(): void
    {
        $unite = DB::table('unite_article')->where('numero_serie', 'OND-DEMO-003')->first();

        $this->actingAs($this->admin)->get('/recherche?q='.$unite->uid)->assertRedirect("/stock/{$unite->id}");
        $this->actingAs($this->admin)->get('/recherche?q=OND-DEMO-003')->assertRedirect("/stock/{$unite->id}");
        $this->actingAs($this->admin)->get('/recherche?q=inconnu')->assertOk();
    }
}
