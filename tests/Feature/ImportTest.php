<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private User $gestion;

    protected function setUp(): void
    {
        parent::setUp();
        config(['securite.mfa_obligatoire' => false]);
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->gestion = tap(User::factory()->create(), fn ($u) => $u->assignRole('Gestionnaire référentiel'));
    }

    /** Classeur au format de l'export CRM. */
    private function classeur(array $articles, array $kits): UploadedFile
    {
        $classeur = new Spreadsheet;
        $parts = $classeur->getActiveSheet()->setTitle('products_parts');
        $parts->fromArray([['Catégorie', 'Nom', 'Type', 'Fabricant', 'Référence', 'Produits associés'], ...$articles]);
        $series = $classeur->createSheet()->setTitle('products_serialized');
        $series->fromArray([['Catégorie', 'Nom', 'Fabricant', 'Verrouillable', 'Référence', 'Parts'], ...$kits]);

        $chemin = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'Référence Kit et Articles.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function importer(UploadedFile $fichier): array
    {
        $this->actingAs($this->gestion)->post('/import/analyser', ['fichier' => $fichier])->assertRedirect('/import');
        $apercu = session('import.apercu');
        if (! $apercu['erreurs']) {
            $this->actingAs($this->gestion)->post('/import/appliquer', ['jeton' => $apercu['jeton']])->assertSessionHas('succes');
        }

        return $apercu;
    }

    public function test_import_puis_reimport_sans_doublon(): void
    {
        $articles = [
            ['Part', 'Spark Battery', 'Sérialisé', 'Spark', 'BAT-1', 'TEVIA ECO, TEVIA CLASSIC'],
            ['Part', 'Spark Cable', 'Non sérialisé', 'Spark ', 'CBL-1', 'TEVIA ECO'],
            ['Part', 'Spark Globe', 'Non sérialisé', 'Spark', 'GLB-1', 'TEVIA CLASSIC'],
        ];
        $kits = [
            ['Energy › TEVIA', 'TEVIA ECO', 'RuralSpark', 'Oui', 'TEVIAECO', 2],
            ['Energy › TEVIA', 'TEVIA CLASSIC', 'RuralSpark', 'Oui', 'TEVIACLASSIC', 2],
            ['Energy › GREENO', 'GREENO_Moaye', 'SunCulture', 'Oui', 'KIT-MY340W', 0],
        ];

        $apercu = $this->importer($this->classeur($articles, $kits));
        $this->assertSame([], $apercu['erreurs']);
        $this->assertCount(3, $apercu['articles']['nouveaux']);
        $this->assertSame(2, $apercu['nomenclatures']['nouvelles']);

        $this->assertSame(3, DB::table('article')->count());
        $this->assertSame(1, DB::table('fabricant')->where('nom', 'Spark')->count()); // espace final ignoré
        $this->assertTrue((bool) DB::table('article')->where('reference', 'BAT-1')->value('est_serialise'));
        $this->assertSame(3, DB::table('type_kit')->count());
        $eco = DB::table('version_kit')->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')->where('type_kit.reference', 'TEVIAECO')->first(['version_kit.*']);
        $this->assertNull($eco->article_porteur_id);
        $this->assertFalse((bool) $eco->verrouillee);
        $this->assertSame(2, DB::table('composition')->where('version_kit_id', $eco->id)->count());
        $this->assertSame(0, DB::table('version_kit')->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')->where('type_kit.reference', 'KIT-MY340W')->count());

        // Le gestionnaire choisit le porteur et verrouille TEVIA ECO.
        $batterie = DB::table('article')->where('reference', 'BAT-1')->value('id');
        $cable = DB::table('article')->where('reference', 'CBL-1')->value('id');
        $this->actingAs($this->gestion)->post("/versions/{$eco->id}/verrouiller")->assertSessionHas('alerte'); // pas de porteur
        $this->actingAs($this->gestion)->put("/versions/{$eco->id}", ['porteur_id' => $batterie, 'lignes' => [['article_id' => $cable, 'quantite' => 2]]])->assertSessionHasNoErrors();
        $this->actingAs($this->gestion)->post("/versions/{$eco->id}/verrouiller")->assertSessionHas('succes');

        // Réimport identique : rien ne change, la version verrouillée est intacte.
        $apercu = $this->importer($this->classeur($articles, $kits));
        $this->assertSame(3, $apercu['articles']['inchanges']);
        $this->assertSame(2, $apercu['nomenclatures']['inchangees']);
        $this->assertSame(3, DB::table('article')->count());
        $this->assertSame(2, DB::table('version_kit')->count());

        // Un article ajouté à TEVIA ECO : nouvelle version brouillon, porteur et quantités conservés.
        $articles[2][5] = 'TEVIA CLASSIC, TEVIA ECO';
        $this->importer($this->classeur($articles, $kits));
        $v2 = DB::table('version_kit')->where('type_kit_id', $eco->type_kit_id)->where('numero', 2)->first();
        $this->assertFalse((bool) $v2->verrouillee);
        $this->assertSame($batterie, $v2->article_porteur_id);
        $this->assertSame(2, DB::table('composition')->where(['version_kit_id' => $v2->id, 'article_id' => $cable])->value('quantite'));
        $this->assertSame(3, DB::table('composition')->where('version_kit_id', $v2->id)->count());
    }

    public function test_fichier_avec_erreurs_refuse(): void
    {
        $apercu = $this->importer($this->classeur(
            [
                ['Part', 'Batterie', 'Sérialisé', '', 'BAT-1', 'KIT INCONNU'],
                ['Part', 'Doublon', 'Peut-être', 'Spark', 'BAT-1', ''],
            ],
            [['Energy', 'Kit', 'Spark', 'Oui', 'K1', 0]],
        ));

        $this->assertNotEmpty($apercu['erreurs']);
        $texte = implode("\n", $apercu['erreurs']);
        $this->assertStringContainsString('fabricant', $texte);
        $this->assertStringContainsString('KIT INCONNU', $texte);
        $this->assertStringContainsString('deux fois', $texte);
        $this->assertStringContainsString('Peut-être', $texte);
        $this->assertSame(0, DB::table('article')->count());

        // Un jeton qui ne correspond pas à l'aperçu est refusé.
        $this->actingAs($this->gestion)->post('/import/appliquer', ['jeton' => '9b2c4a7e-1d3f-4e8a-9c21-7d4e5f6a8b90'])->assertSessionHas('alerte');
    }

    public function test_reserve_aux_gestionnaires(): void
    {
        $lecteur = tap(User::factory()->create(), fn ($u) => $u->assignRole('Lecteur'));
        $this->actingAs($lecteur)->get('/import')->assertForbidden();
    }
}
