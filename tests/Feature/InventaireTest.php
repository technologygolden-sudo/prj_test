<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Campagnes d'inventaire (RG35 à RG38) et listes de valeurs. */
class InventaireTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $entrepot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['securite.mfa_obligatoire' => false]);
        $this->seed(DemoSeeder::class);
        $this->admin = User::where('login', 'admin.demo')->firstOrFail();
        $this->entrepot = DB::table('site')->where('code', SiteSeeder::ENTREPOT)->value('id');
        DB::table('article')->where('reference', 'OND-5K')->update(['cout_unitaire' => 350000]);
        DB::table('article')->where('reference', 'PAN-400')->update(['cout_unitaire' => 60000]);
    }

    private function campagne(): int
    {
        $onduleur = DB::table('article')->where('reference', 'OND-5K')->value('id');
        $panneau = DB::table('article')->where('reference', 'PAN-400')->value('id');
        $this->actingAs($this->admin)->post('/inventaires', [
            'libelle' => 'Inventaire annuel', 'date_prevue' => now()->toDateString(), 'sites' => [$this->entrepot],
            'tous_articles' => false, 'tous_kits' => false, 'articles' => [$onduleur, $panneau],
        ])->assertRedirect();

        return DB::table('inventaire')->value('id');
    }

    private function compter(int $id, array $corps): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/inventaires/{$id}/compter", ['site_id' => $this->entrepot] + $corps);
    }

    public function test_cycle_complet_avec_second_comptage_valorisation_et_ajustement(): void
    {
        $id = $this->campagne();
        $this->actingAs($this->admin)->post("/inventaires/{$id}/lancer")->assertSessionHas('succes');

        // RG35 : théorique figé (10 onduleurs, 30 panneaux).
        $theorique = DB::table('inventaire_ligne')->where('inventaire_id', $id)->join('article', 'article.id', '=', 'inventaire_ligne.article_id')->pluck('theorique', 'article.reference');
        $this->assertSame(['OND-5K' => 10, 'PAN-400' => 30], $theorique->sortKeys()->all());

        // 1er comptage : 9 onduleurs scannés (OND-DEMO-010 manquant), panneaux saisis 28.
        for ($i = 1; $i <= 9; $i++) {
            $this->compter($id, ['code' => sprintf('OND-DEMO-%03d', $i)])->assertOk();
        }
        $this->compter($id, ['code' => 'OND-DEMO-001'])->assertStatus(422); // déjà compté
        $this->compter($id, ['code' => 'BAT-DEMO-001'])->assertStatus(422); // hors périmètre
        $panneaux = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')->where('article.reference', 'PAN-400')->value('unite_article.uid');
        $this->compter($id, ['code' => $panneaux, 'quantite' => 28])->assertOk()->assertJson(['total' => 28]);
        $this->compter($id, ['code' => 'INCONNU-42'])->assertOk(); // n° hors système

        $this->actingAs($this->admin)->post("/inventaires/{$id}/terminer-comptage");
        $this->assertSame('analyse', DB::table('inventaire')->where('id', $id)->value('statut'));

        // RG36 : 2e comptage seulement sur les panneaux ; on en retrouve 29.
        $lignePanneaux = DB::table('inventaire_ligne')->join('article', 'article.id', '=', 'inventaire_ligne.article_id')
            ->where('inventaire_id', $id)->where('article.reference', 'PAN-400')->value('inventaire_ligne.id');
        $this->actingAs($this->admin)->post("/inventaires/{$id}/second-comptage", ['lignes' => [$lignePanneaux]])->assertSessionHas('succes');
        $this->compter($id, ['code' => 'OND-DEMO-001'])->assertStatus(422); // non concerné par le 2e comptage
        $this->compter($id, ['code' => $panneaux, 'quantite' => 29])->assertOk();
        $this->actingAs($this->admin)->post("/inventaires/{$id}/terminer-comptage");
        $this->assertSame('decompte', DB::table('inventaire')->where('id', $id)->value('statut'));

        // Décompte final : justification exigée si la quantité diffère du dernier comptage.
        $this->actingAs($this->admin)->put("/inventaires/lignes/{$lignePanneaux}", ['final' => 30])->assertSessionHasErrors('justification');
        $this->actingAs($this->admin)->put("/inventaires/lignes/{$lignePanneaux}", ['final' => 29])->assertSessionHas('succes');

        // RG37 : écarts valorisés : −1 onduleur × 350 000 et −1 panneau × 60 000.
        $this->assertEquals(-410000, (float) DB::table('inventaire')->where('id', $id)->value('valeur_ecart'));
        $this->actingAs($this->admin)->get("/inventaires/{$id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Stock/Inventaires/Fiche')->has('inconnus', 1));

        // RG38 : clôture avec ajustement du stock.
        $this->actingAs($this->admin)->post("/inventaires/{$id}/cloturer", ['ajuster' => true])->assertSessionHas('succes');
        $this->assertSame('perdu', DB::table('unite_article')->where('numero_serie', 'OND-DEMO-010')->value('statut'));
        $this->assertSame(29, (int) DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->where('article.reference', 'PAN-400')->where('unite_article.statut', 'en_stock')->sum('quantite_restante'));
        $this->assertSame('cloture', DB::table('inventaire')->where('id', $id)->value('statut'));
    }

    public function test_droits_et_pages(): void
    {
        $id = $this->campagne();
        $magasin = User::where('login', 'magasin.demo')->first();

        $this->actingAs($magasin)->get('/inventaires')->assertOk(); // compteur
        $this->actingAs($magasin)->post("/inventaires/{$id}/lancer")->assertForbidden();
        $this->actingAs(User::where('login', 'planif.demo')->first())->get('/inventaires')->assertForbidden();

        foreach (['/inventaires' => 'Stock/Inventaires/Index', '/inventaires/nouveau' => 'Stock/Inventaires/Parametres',
            "/inventaires/{$id}" => 'Stock/Inventaires/Fiche', "/inventaires/{$id}/parametres" => 'Stock/Inventaires/Parametres'] as $url => $composant) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component($composant));
        }
    }

    public function test_listes_de_valeurs_crud(): void
    {
        $this->actingAs($this->admin)->get('/listes?liste=technicien')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('liste', 'technicien'));
        $this->actingAs($this->admin)->post('/listes', ['liste' => 'technicien', 'libelle' => 'Koné Ibrahim'])->assertSessionHas('succes');
        $this->actingAs($this->admin)->post('/listes', ['liste' => 'technicien', 'libelle' => 'Koné Ibrahim'])->assertSessionHasErrors('libelle');
        $id = DB::table('liste_valeur')->where('libelle', 'Koné Ibrahim')->value('id');
        $this->actingAs($this->admin)->put("/listes/{$id}", ['libelle' => 'Koné I.', 'actif' => false, 'ordre' => 1])->assertSessionHas('succes');
        $this->assertFalse((bool) DB::table('liste_valeur')->where('id', $id)->value('actif'));
        $this->actingAs($this->admin)->delete("/listes/{$id}")->assertSessionHas('succes');
        $this->assertNull(DB::table('liste_valeur')->where('id', $id)->first());
        $this->actingAs(User::where('login', 'lecteur.demo')->first())->get('/listes')->assertForbidden();
    }
}
