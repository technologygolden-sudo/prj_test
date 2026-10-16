<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SiteSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Emplacements, transferts, SAV / Lab et remise au technicien (RG28 à RG32). */
class EmplacementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['securite.mfa_obligatoire' => false]);
        $this->seed(DemoSeeder::class);
        $this->admin = User::where('login', 'admin.demo')->firstOrFail();
    }

    private function emplacement(string $type, ?string $espace = 'GREENO', string $site = SiteSeeder::ENTREPOT): int
    {
        return DB::table('emplacement')->join('site', 'site.id', '=', 'emplacement.site_id')
            ->where(['site.code' => $site, 'emplacement.type' => $type])
            ->when($espace, fn ($q) => $q->where('emplacement.espace', $espace), fn ($q) => $q->whereNull('emplacement.espace'))
            ->value('emplacement.id');
    }

    private function unite(string $serie): object
    {
        return DB::table('unite_article')->where('numero_serie', $serie)->first();
    }

    private function lot(string $reference): object
    {
        return DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->where('article.reference', $reference)->select('unite_article.*')->first();
    }

    public function test_sites_et_emplacements_crees(): void
    {
        $this->assertSame(13, DB::table('site')->count());
        $this->assertSame(10 + 12 * 3, DB::table('emplacement')->count());
        $this->assertSame(2 + 12, DB::table('emplacement')->where('vente', true)->count());
    }

    public function test_reception_dans_l_espace_choisi(): void
    {
        $onduleur = DB::table('article')->where('reference', 'OND-5K')->value('id');
        $this->actingAs($this->admin)->post('/receptions', [
            'date' => now()->toDateString(), 'num_bl' => 'BL-T1', 'fournisseur' => 'Huawei', 'espace' => 'TEVIA',
            'lignes' => [['article_id' => $onduleur, 'quantite' => 1, 'series' => ['T-001']]],
        ])->assertRedirect();

        $u = $this->unite('T-001');
        $this->assertSame('TEVIA', $u->proprietaire);
        $this->assertSame($this->emplacement('reception', 'TEVIA'), $u->emplacement_id);
        $this->assertSame('TEVIA', DB::table('reception')->where('num_bl', 'BL-T1')->value('espace'));

        $this->actingAs($this->admin)->post('/receptions', [
            'date' => now()->toDateString(), 'num_bl' => 'BL-T2', 'fournisseur' => 'Huawei',
            'lignes' => [['article_id' => $onduleur, 'quantite' => 1, 'series' => ['T-002']]],
        ])->assertSessionHasErrors('espace');
    }

    public function test_deplacement_motive_scission_de_lot_et_espaces(): void
    {
        $u = $this->unite('OND-DEMO-001');
        $lab = $this->emplacement('lab');

        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $lab])->assertSessionHasErrors('motif');
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $lab, 'motif' => 'Contrôle QA/QC'])->assertSessionHas('succes');
        $this->assertSame($lab, DB::table('unite_article')->where('id', $u->id)->value('emplacement_id'));
        $this->assertDatabaseHas('mouvement_emplacement', ['unite_id' => $u->id, 'type' => 'deplacement', 'motif' => 'Contrôle QA/QC']);

        // RG29 : un article GREENO ne va pas dans l'espace TEVIA par simple déplacement.
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $this->emplacement('entrepot', 'TEVIA'), 'motif' => 'Erreur'])
            ->assertSessionHasErrors('emplacement_id');
        // RG30 : un autre site passe par un transfert.
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $this->emplacement('vente', null, 'CS-DIVO'), 'motif' => 'Réassort'])
            ->assertSessionHasErrors('emplacement_id');

        // Une partie d'un lot crée un nouveau lot avec son UID.
        $panneaux = $this->lot('PAN-400');
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $panneaux->id, 'emplacement_id' => $this->emplacement('shop'), 'motif' => 'Réassort du shop', 'quantite' => 8])
            ->assertRedirect();
        $this->assertSame(22, DB::table('unite_article')->where('id', $panneaux->id)->value('quantite_restante'));
        $nouveau = DB::table('unite_article')->where('article_id', $panneaux->article_id)->where('id', '<>', $panneaux->id)->first();
        $this->assertSame(8, $nouveau->quantite_restante);
        $this->assertSame($this->emplacement('shop'), $nouveau->emplacement_id);
        $this->assertStringStartsWith('L-', $nouveau->uid);
    }

    public function test_cession_greeno_vers_tevia(): void
    {
        $u = $this->unite('OND-DEMO-002');
        $this->actingAs($this->admin)->post('/emplacements/ceder', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $this->emplacement('entrepot', 'TEVIA'), 'motif' => 'Besoin TEVIA'])
            ->assertSessionHas('succes');
        $this->assertSame('TEVIA', DB::table('unite_article')->where('id', $u->id)->value('proprietaire'));
        $this->assertDatabaseHas('mouvement_emplacement', ['unite_id' => $u->id, 'type' => 'cession']);
    }

    public function test_rg28_defaut_sav_puis_reparation_au_lab(): void
    {
        $u = $this->unite('OND-DEMO-003');
        $shop = $this->emplacement('shop');
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $shop, 'motif' => 'Mise en vente']);

        // Le SAV déclare le défaut : l'élément doit quitter le shop.
        $this->actingAs($this->admin)->post('/etat/defaut', ['type' => 'unite', 'id' => $u->id, 'description' => 'Ne démarre plus', 'emplacement_id' => $shop])->assertSessionHasErrors('emplacement_id');
        $this->actingAs($this->admin)->post('/etat/defaut', ['type' => 'unite', 'id' => $u->id, 'description' => 'Ne démarre plus', 'emplacement_id' => $this->emplacement('sav')])->assertSessionHas('succes');
        $this->assertSame('defectueux', DB::table('unite_article')->where('id', $u->id)->value('etat'));

        // Défectueux : interdit en shop, en application et en base.
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $shop, 'motif' => 'Retour en vente'])->assertSessionHasErrors('emplacement_id');
        try {
            DB::transaction(fn () => DB::table('unite_article')->where('id', $u->id)->update(['emplacement_id' => $shop]));
            $this->fail('RG28 aurait dû être refusée par la base.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('RG28', $e->getMessage());
        }

        // Le Lab répare, seulement au Lab.
        $this->actingAs($this->admin)->post('/etat/reparation', ['type' => 'unite', 'id' => $u->id, 'nature' => 'reparation', 'description' => 'Carte remplacée'])->assertSessionHasErrors('cible');
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $this->emplacement('lab'), 'motif' => 'Réparation']);
        $this->actingAs($this->admin)->post('/etat/reparation', ['type' => 'unite', 'id' => $u->id, 'nature' => 'reparation', 'description' => 'Carte remplacée'])->assertSessionHas('succes');
        $this->assertSame('bon', DB::table('unite_article')->where('id', $u->id)->value('etat'));
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $shop, 'motif' => 'Retour en vente'])->assertSessionHas('succes');

        // Droits : le SAV ne répare pas, le Lab ne déclare pas de défaut.
        $this->actingAs(User::where('login', 'sav.demo')->first())->post('/etat/reparation', ['type' => 'unite', 'id' => $u->id, 'nature' => 'reconditionnement', 'description' => 'xxxxx'])->assertForbidden();
        $this->actingAs(User::where('login', 'lab.demo')->first())->post('/etat/defaut', ['type' => 'unite', 'id' => $u->id, 'description' => 'xxxxx'])->assertForbidden();
    }

    public function test_transfert_en_transit_puis_reception_partielle(): void
    {
        $divo = DB::table('site')->where('code', 'CS-DIVO')->value('id');
        $entrepot = DB::table('site')->where('code', SiteSeeder::ENTREPOT)->value('id');
        $a = $this->unite('OND-DEMO-004');
        $b = $this->unite('OND-DEMO-005');

        $this->actingAs($this->admin)->postJson('/transferts/verifier', ['code' => 'OND-DEMO-004', 'site_id' => $divo])->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/transferts/verifier', ['code' => 'OND-DEMO-004', 'site_id' => $entrepot])->assertOk()->assertJson(['id' => $a->id]);

        $this->actingAs($this->admin)->post('/transferts', [
            'site_origine_id' => $entrepot, 'site_destination_id' => $divo, 'motif' => 'Réassort Divo',
            'lignes' => [['type' => 'unite', 'id' => $a->id], ['type' => 'unite', 'id' => $b->id]],
            'prevu' => [['cle' => 'article:'.$a->article_id, 'quantite' => 3]],
        ])->assertSessionHasErrors('lignes'); // le bon prévoit 3, 2 scannés

        $this->actingAs($this->admin)->post('/transferts', [
            'site_origine_id' => $entrepot, 'site_destination_id' => $divo, 'motif' => 'Réassort Divo',
            'lignes' => [['type' => 'unite', 'id' => $a->id], ['type' => 'unite', 'id' => $b->id]],
            'prevu' => [['cle' => 'article:'.$a->article_id, 'quantite' => 2]],
        ])->assertRedirect();

        $transfert = DB::table('transfert')->first();
        $this->assertSame('en_transit', DB::table('unite_article')->where('id', $a->id)->value('statut'));
        $this->assertNull(DB::table('unite_article')->where('id', $a->id)->value('emplacement_id'));

        $vente = $this->emplacement('vente', null, 'CS-DIVO');
        $ligneA = DB::table('transfert_ligne')->where('unite_id', $a->id)->value('id');
        $shopDivo = User::where('login', 'centre.demo')->firstOrFail();

        // Seul un utilisateur du site d'arrivée valide la réception (RG33).
        $this->actingAs($this->admin)->post("/transferts/{$transfert->id}/recevoir", ['arrivees' => [$ligneA => $vente]])->assertSessionHas('alerte');
        // Un emplacement d'un autre site est refusé.
        $this->actingAs($shopDivo)->post("/transferts/{$transfert->id}/recevoir", ['arrivees' => [$ligneA => $this->emplacement('shop')]])->assertSessionHasErrors('lignes');

        $this->actingAs($shopDivo)->post("/transferts/{$transfert->id}/recevoir", ['arrivees' => [$ligneA => $vente]])->assertSessionHas('succes');
        $this->assertSame('recu_partiel', DB::table('transfert')->where('id', $transfert->id)->value('statut'));
        $this->assertSame('en_stock', DB::table('unite_article')->where('id', $a->id)->value('statut'));
        $this->assertSame($vente, DB::table('unite_article')->where('id', $a->id)->value('emplacement_id'));
        $this->assertSame('en_transit', DB::table('unite_article')->where('id', $b->id)->value('statut'));

        $ligneB = DB::table('transfert_ligne')->where('unite_id', $b->id)->value('id');
        $this->actingAs($shopDivo)->post("/transferts/{$transfert->id}/recevoir", ['arrivees' => [$ligneB => $this->emplacement('retour', null, 'CS-DIVO')]]);
        $this->assertSame('recu', DB::table('transfert')->where('id', $transfert->id)->value('statut'));

        // Le shop manager de Divo ne voit que son site : pas l'entrepôt.
        $this->actingAs($shopDivo)->get('/stock/'.$this->unite('OND-DEMO-006')->id)->assertForbidden();
        $this->actingAs($shopDivo)->get('/stock/'.$a->id)->assertOk();
        $this->actingAs($shopDivo)->get('/stock?vue=sites')->assertInertia(fn (AssertableInertia $p) => $p->has('parSite', 1)->where('parSite.0.site', 'Centre de service Divo'));
    }

    public function test_assemblage_preleve_automatiquement_les_articles_non_serialises(): void
    {
        $version = DB::table('version_kit')->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')->where('type_kit.reference', 'KIT-A')->value('version_kit.id');
        $porteur = $this->unite('OND-DEMO-007');

        // Seul le porteur est scanné : 4 panneaux et 1 coffret sont pris dans les lots du site.
        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => [['unite_id' => $porteur->id, 'quantite' => 1]]])->assertRedirect();
        $this->assertSame(26, $this->lot('PAN-400')->quantite_restante);
        $this->assertSame(5, $this->lot('COF-AC')->quantite_restante);
        $this->assertSame(3, DB::table('contenu_kit')->count());

        // Sans le porteur, refusé.
        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => [['unite_id' => $this->lot('PAN-400')->id, 'quantite' => 4]]])
            ->assertSessionHasErrors('composants');
    }

    public function test_rg31_seul_un_shop_remet_au_technicien(): void
    {
        $u = $this->unite('BAT-DEMO-001');
        $sortie = ['type' => 'expedition', 'destinataire' => 'Technicien Koné', 'document' => 'CRM-1234', 'lignes' => [['type' => 'unite', 'id' => $u->id, 'quantite' => 1]]];

        $this->actingAs($this->admin)->post('/sorties', $sortie)->assertSessionHasErrors('lignes');
        $this->actingAs($this->admin)->post('/sorties', ['destinataire' => null] + $sortie)->assertSessionHasErrors('destinataire');

        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'unite', 'id' => $u->id, 'emplacement_id' => $this->emplacement('shop'), 'motif' => 'Vente']);
        $this->actingAs($this->admin)->post('/sorties', $sortie)->assertRedirect('/sorties');
        $this->assertSame('expedie', DB::table('unite_article')->where('id', $u->id)->value('statut'));
        $this->assertNull(DB::table('unite_article')->where('id', $u->id)->value('emplacement_id'));
    }

    public function test_kit_herite_de_l_emplacement_et_controle_du_proprietaire(): void
    {
        $version = DB::table('version_kit')->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')->where('type_kit.reference', 'KIT-A')->value('version_kit.id');
        $porteur = $this->unite('OND-DEMO-006');
        $panneaux = $this->lot('PAN-400');
        $coffret = $this->lot('COF-AC');
        $composants = [['unite_id' => $porteur->id, 'quantite' => 1], ['unite_id' => $panneaux->id, 'quantite' => 4], ['unite_id' => $coffret->id, 'quantite' => 1]];

        // Composants de propriétaires différents : refusé.
        DB::table('unite_article')->where('id', $porteur->id)->update(['proprietaire' => 'TEVIA', 'emplacement_id' => $this->emplacement('entrepot', 'TEVIA')]);
        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => $composants])->assertSessionHasErrors('composants');
        DB::table('unite_article')->where('id', $porteur->id)->update(['proprietaire' => 'GREENO', 'emplacement_id' => $this->emplacement('lab')]);

        $this->actingAs($this->admin)->post('/assemblage', ['version_id' => $version, 'composants' => $composants])->assertRedirect();
        $kit = DB::table('kit_assemble')->first();
        $this->assertSame($this->emplacement('lab'), $kit->emplacement_id);
        $this->assertSame('GREENO', $kit->proprietaire);
        $this->assertNull(DB::table('unite_article')->where('id', $porteur->id)->value('emplacement_id'));

        // Le kit se déplace vers le shop, puis le désassemblage rend l'emplacement au porteur.
        $this->actingAs($this->admin)->post('/emplacements/deplacer', ['type' => 'kit', 'id' => $kit->id, 'emplacement_id' => $this->emplacement('shop'), 'motif' => 'Mise en vente'])->assertSessionHas('succes');
        $this->actingAs($this->admin)->post("/kits-assembles/{$kit->id}/desassembler", ['motif' => 'Erreur de composition']);
        $this->assertSame($this->emplacement('shop'), DB::table('unite_article')->where('id', $porteur->id)->value('emplacement_id'));
    }

    public function test_vue_stock_par_site_et_pages(): void
    {
        $this->actingAs($this->admin)->get('/stock?vue=sites')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Stock/Unites/Index')->where('vue', 'sites')
                ->where('parSite.0.site', 'Entrepôt Abidjan')->where('parSite.0.total_articles', 51)->has('parSite', 13));

        foreach (['/transferts' => 'Stock/Transferts/Index', '/transferts/nouveau' => 'Stock/Transferts/Creer', '/admin/parametres' => 'Admin/Parametres'] as $url => $composant) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component($composant));
        }

        $this->actingAs($this->admin)->post('/admin/sites', ['ville' => 'Daloa'])->assertSessionHas('succes');
        $this->assertSame(3, DB::table('emplacement')->join('site', 'site.id', '=', 'emplacement.site_id')->where('site.code', 'CS-DALOA')->count());
        $this->actingAs(User::where('login', 'lecteur.demo')->first())->get('/transferts')->assertForbidden();
    }
}
