<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdministrationTest extends TestCase
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

    public function test_rg15_parametres_societe_reserves_et_journalises(): void
    {
        $this->actingAs(User::where('login', 'gestion.demo')->first())
            ->put('/admin/parametres/societe', ['nom_societe' => 'X', 'num_sav' => '0'])->assertForbidden();

        $this->actingAs($this->admin)->put('/admin/parametres/societe', ['nom_societe' => 'GREENO SOLAR', 'num_sav' => '01 02 03'])->assertSessionHas('succes');
        $this->assertSame('GREENO SOLAR', DB::table('parametre_societe')->value('nom_societe'));
        $this->assertDatabaseHas('journal_audit', ['action' => 'modifier', 'objet_type' => 'parametre_societe']);
    }

    public function test_rg16_rg19_roles_et_permissions_sensibles(): void
    {
        $administrateur = Role::findByName(PermissionSeeder::ADMINISTRATEUR);
        $lecteur = Role::findByName('Lecteur');

        $this->actingAs($this->admin)->put("/admin/roles/{$administrateur->id}", ['permissions' => [], 'actif' => true])->assertSessionHas('alerte');
        $this->assertSame(count(PermissionSeeder::CATALOGUE), $administrateur->permissions()->count());

        // Permission non sensible : pas de MFA.
        $this->actingAs($this->admin)->put("/admin/roles/{$lecteur->id}", [
            'permissions' => ['articles.voir', 'recherche.scanner', 'capacite.consulter', 'simulation.lancer'], 'actif' => true,
        ])->assertSessionHas('succes');

        // Permission ★ : code MFA exigé (RG19).
        $avecSensible = ['permissions' => ['articles.voir', 'stock.ajuster'], 'actif' => true];
        $this->actingAs($this->admin)->put("/admin/roles/{$lecteur->id}", $avecSensible)->assertSessionHasErrors('code_mfa');

        $secret = app(Google2FA::class)->generateSecretKey();
        $this->admin->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now()])->save();
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->actingAs($this->admin)->put("/admin/roles/{$lecteur->id}", $avecSensible + ['code_mfa' => $code])->assertSessionHas('succes');
        $this->assertTrue($lecteur->fresh()->hasPermissionTo('stock.ajuster'));
    }

    public function test_journal_d_audit_consultable_et_exportable(): void
    {
        $this->actingAs($this->admin)->put('/admin/parametres/societe', ['nom_societe' => 'GREENO', 'num_sav' => '1']);

        $this->actingAs($this->admin)->get('/admin/audit?action=modifier')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Audit')->where('lignes.data.0.objet_type', 'parametre_societe'));
        $csv = $this->actingAs($this->admin)->get('/admin/audit/export');
        $csv->assertOk();
        $this->assertStringContainsString('parametre_societe', $csv->streamedContent());
    }

    public function test_imprimante_adresse_validee(): void
    {
        $this->actingAs($this->admin)->post('/admin/imprimantes', [
            'nom' => 'Zebra quai', 'adresse' => 'http://evil', 'modele' => 'ZD411', 'dpi' => 203, 'actif' => true,
        ])->assertSessionHasErrors('adresse');
        $this->actingAs($this->admin)->post('/admin/imprimantes', [
            'nom' => 'Zebra quai', 'adresse' => 'tcp://10.0.20.15:9100', 'modele' => 'ZD411', 'dpi' => 300, 'actif' => true,
        ])->assertSessionHas('succes');
    }

    public function test_toutes_les_nouvelles_pages_s_affichent(): void
    {
        $unite = DB::table('unite_article')->value('id');
        $reception = DB::table('reception')->value('id');

        foreach ([
            '/receptions' => 'Stock/Receptions/Index', '/receptions/nouvelle' => 'Stock/Receptions/Creer', "/receptions/{$reception}" => 'Stock/Receptions/Fiche',
            '/stock' => 'Stock/Unites/Index', "/stock/{$unite}" => 'Stock/Unites/Fiche', '/sorties' => 'Stock/Sorties/Index', '/sorties/nouvelle' => 'Stock/Sorties/Creer',
            '/assemblage' => 'Stock/Assemblage', '/kits-assembles' => 'Stock/Kits/Index', '/capacite' => 'Simulation/Capacite',
            '/simulations' => 'Simulation/Index', '/simulations/nouvelle' => 'Simulation/Creer',
            '/admin/roles' => 'Admin/Roles', '/admin/parametres' => 'Admin/Parametres', '/admin/audit' => 'Admin/Audit',
        ] as $url => $composant) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component($composant));
        }

        $version = DB::table('version_kit')->value('id');
        $this->actingAs($this->admin)->get("/assemblage?version={$version}")->assertInertia(fn (AssertableInertia $p) => $p->has('nomenclature.lignes', 3));
    }
}
