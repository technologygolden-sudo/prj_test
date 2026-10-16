<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Simulation (EF08 à EF11) : le service Python est simulé ici ; il a ses propres tests (optimiseur/test_solveur.py). */
class SimulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $versions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['securite.mfa_obligatoire' => false, 'services.optimiseur.jeton' => 'jeton-test', 'services.optimiseur.url' => 'http://optimiseur.test']);
        $this->seed(DemoSeeder::class);
        $this->admin = User::where('login', 'admin.demo')->firstOrFail();
        $this->versions = DB::table('version_kit')->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->pluck('version_kit.id', 'type_kit.reference')->all();
    }

    public function test_simulation_sur_le_stock_reel_exemple_5_3(): void
    {
        Http::fake(['optimiseur.test/*' => Http::response([
            'statut' => 'termine', 'optimalite' => 'optimal', 'duree_ms' => 12,
            'quantites' => [(string) $this->versions['KIT-A'] => 5, (string) $this->versions['KIT-B'] => 5],
        ])]);
        $avant = DB::table('unite_article')->sum('quantite_restante');

        $this->actingAs($this->admin)->post('/simulations', [
            'mode' => 'volume', 'source_stock' => 'reel',
            'demandes' => [
                ['version_id' => $this->versions['KIT-A'], 'qte' => 8, 'rang' => 1],
                ['version_id' => $this->versions['KIT-B'], 'qte' => 5, 'rang' => 1],
            ],
        ])->assertRedirect();

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer jeton-test') && $r['mode'] === 'volume');

        $simulation = DB::table('simulation')->first();
        $this->assertSame('optimal', $simulation->optimalite);
        $stock = DB::table('sim_stock')->join('article', 'article.id', '=', 'sim_stock.article_id')->get()->keyBy('reference');
        // Tableau 5.3 : besoin / manquant / consommé / reliquat.
        $this->assertSame([10, 3, 10, 0], [$stock['OND-5K']->qte_disponible, $stock['OND-5K']->qte_manquante, $stock['OND-5K']->qte_consommee, $stock['OND-5K']->qte_reliquat]);
        $this->assertSame([30, 12, 30, 0], [$stock['PAN-400']->qte_disponible, $stock['PAN-400']->qte_manquante, $stock['PAN-400']->qte_consommee, $stock['PAN-400']->qte_reliquat]);
        $this->assertSame([6, 2, 5, 1], [$stock['COF-AC']->qte_disponible, $stock['COF-AC']->qte_manquante, $stock['COF-AC']->qte_consommee, $stock['COF-AC']->qte_reliquat]);

        // RG13 : le stock réel n'a pas bougé.
        $this->assertSame($avant, DB::table('unite_article')->sum('quantite_restante'));

        // Capacités seules : A limité par le coffret (6), B par la batterie (5).
        $this->actingAs($this->admin)->get("/simulations/{$simulation->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Simulation/Resultat')
                ->where('kits.0.capacite', 6)->where('kits.0.limitant', 'COF-AC')
                ->where('kits.1.capacite', 5)->where('kits.1.limitant', 'BAT-5'));

        $csv = $this->actingAs($this->admin)->get("/simulations/{$simulation->id}/export/achats");
        $csv->assertOk();
        $this->assertStringContainsString('PAN-400;"Panneau 400 W";12', $csv->streamedContent());
        $this->actingAs($this->admin)->get("/simulations/{$simulation->id}/export/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_service_indisponible(): void
    {
        Http::fake(['optimiseur.test/*' => Http::response(null, 500)]);

        $this->actingAs($this->admin)->post('/simulations', [
            'mode' => 'priorite', 'source_stock' => 'saisie',
            'demandes' => [['version_id' => $this->versions['KIT-A'], 'qte' => 1, 'rang' => 1]],
            'stock' => [],
        ])->assertSessionHasErrors('mode');
        $this->assertSame(0, DB::table('simulation')->count());
    }

    public function test_capacite_sur_le_stock_reel(): void
    {
        $this->actingAs(User::where('login', 'lecteur.demo')->first())->get('/capacite')->assertOk()
            ->assertInertia(fn ($page) => $page->where('lignes.0.capacite', 6)->where('lignes.1.capacite', 5));
    }
}
