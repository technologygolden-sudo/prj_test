<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Fabricant;
use App\Models\TypeKit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReferentielTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['securite.mfa_obligatoire' => false]);
    }

    private function utilisateur(string $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role));
    }

    public function test_un_visiteur_est_renvoye_vers_la_connexion(): void
    {
        $this->get('/articles')->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Connexion'));
    }

    public function test_un_compte_desactive_ne_peut_pas_se_connecter(): void
    {
        $user = User::factory()->create(['login' => 'ancien', 'password' => 'Secret-Test-2026', 'actif' => false]);

        $this->post('/login', ['login' => 'ancien', 'password' => 'Secret-Test-2026'])->assertSessionHasErrors('login');
        $this->assertGuest();

        $user->update(['actif' => true]);
        $this->post('/login', ['login' => 'ancien', 'password' => 'Secret-Test-2026'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->derniere_connexion);
    }

    public function test_enf01_un_administrateur_sans_mfa_est_renvoye_vers_securite(): void
    {
        config(['securite.mfa_obligatoire' => true]);
        $admin = $this->utilisateur(PermissionSeeder::ADMINISTRATEUR);
        $lecteur = $this->utilisateur('Lecteur');

        $this->actingAs($admin)->get('/articles')->assertRedirect('/compte/securite');
        $this->actingAs($admin)->get('/compte/securite')->assertOk();
        $this->actingAs($lecteur)->get('/articles')->assertOk(); // aucune permission sensible

        $admin->forceFill(['two_factor_secret' => encrypt('X'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($admin)->get('/articles')->assertOk();
    }

    public function test_toutes_les_pages_s_affichent_pour_l_administrateur(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $admin = User::where('login', 'admin.demo')->firstOrFail();

        foreach ([
            '/' => 'Accueil',
            '/compte/securite' => 'Compte/Securite',
            '/articles' => 'Referentiel/Articles/Index',
            '/articles/nouveau' => 'Referentiel/Articles/Formulaire',
            '/articles/'.Article::first()->id.'/modifier' => 'Referentiel/Articles/Formulaire',
            '/categories' => 'Referentiel/ListeSimple',
            '/fabricants' => 'Referentiel/ListeSimple',
            '/kits' => 'Referentiel/Kits/Index',
            '/kits/nouveau' => 'Referentiel/Kits/Creer',
            '/kits/'.TypeKit::first()->id => 'Referentiel/Kits/Fiche',
            '/admin/utilisateurs' => 'Admin/Utilisateurs',
        ] as $url => $composant) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component($composant));
        }
    }

    public function test_rg22_les_droits_sont_controles_cote_serveur(): void
    {
        $lecteur = $this->utilisateur('Lecteur');

        $this->actingAs($lecteur)->get('/articles')->assertOk();
        $this->actingAs($lecteur)->get('/categories')->assertForbidden();
        $this->actingAs($lecteur)->post('/articles', ['reference' => 'X', 'nom' => 'X', 'est_serialise' => false])->assertForbidden();
        $this->actingAs($lecteur)->get('/admin/utilisateurs')->assertForbidden();
        $this->assertSame(0, Article::count());
    }

    public function test_creer_un_article_et_le_journaliser(): void
    {
        $gestion = $this->utilisateur('Gestionnaire référentiel');
        $fabricant = Fabricant::create(['nom' => 'Huawei']);

        $this->actingAs($gestion)->post('/articles', ['reference' => 'OND-1', 'nom' => 'Onduleur', 'est_serialise' => true])
            ->assertSessionHasErrors('fabricant_id');

        $this->actingAs($gestion)->post('/articles', [
            'reference' => 'OND-1', 'nom' => 'Onduleur', 'est_serialise' => true, 'fabricant_id' => $fabricant->id,
        ])->assertRedirect('/articles');

        $this->assertDatabaseHas('article', ['reference' => 'OND-1', 'est_serialise' => true]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'creer', 'objet_type' => 'article', 'utilisateur_id' => $gestion->id]);
    }

    public function test_cycle_de_vie_d_un_type_de_kit(): void
    {
        $gestion = $this->utilisateur('Gestionnaire référentiel');
        $fabricant = Fabricant::create(['nom' => 'Huawei']);
        $onduleur = Article::create(['reference' => 'OND', 'nom' => 'Onduleur', 'est_serialise' => true, 'fabricant_id' => $fabricant->id]);
        $panneau = Article::create(['reference' => 'PAN', 'nom' => 'Panneau', 'est_serialise' => false]);

        // Le porteur doit être sérialisé (RG05).
        $this->actingAs($gestion)->post('/kits', [
            'reference' => 'KIT-A', 'partno' => 'PN-A', 'nom' => 'Kit A', 'porteur_id' => $panneau->id, 'lignes' => [],
        ])->assertSessionHasErrors('porteur_id');

        $this->actingAs($gestion)->post('/kits', [
            'reference' => 'KIT-A', 'partno' => 'PN-A', 'nom' => 'Kit A', 'porteur_id' => $onduleur->id,
            'lignes' => [['article_id' => $panneau->id, 'quantite' => 4]],
        ])->assertRedirect();

        $kit = TypeKit::where('reference', 'KIT-A')->firstOrFail();
        $version = $kit->versions()->firstOrFail();
        $this->assertSame([$onduleur->id => 1, $panneau->id => 4], $version->articles->pluck('pivot.quantite', 'id')->all());

        // Modification du brouillon, puis verrouillage (RG10).
        $this->actingAs($gestion)->put("/versions/{$version->id}", [
            'porteur_id' => $onduleur->id, 'lignes' => [['article_id' => $panneau->id, 'quantite' => 6]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($gestion)->post("/versions/{$version->id}/verrouiller")->assertSessionHas('succes');
        $this->assertSame($version->id, $kit->versionActive()->value('id'));

        $this->actingAs($gestion)->put("/versions/{$version->id}", [
            'porteur_id' => $onduleur->id, 'lignes' => [['article_id' => $panneau->id, 'quantite' => 9]],
        ])->assertSessionHas('alerte');
        $this->assertSame(6, DB::table('composition')->where(['version_kit_id' => $version->id, 'article_id' => $panneau->id])->value('quantite'));

        // Nouvelle version recopiée, puis article porteur qui ne peut plus perdre sa sérialisation.
        $this->actingAs($gestion)->post("/kits/{$kit->id}/versions")->assertSessionHas('succes');
        $this->assertSame(2, $kit->versions()->count());
        $this->actingAs($gestion)->post("/kits/{$kit->id}/versions")->assertSessionHas('alerte');

        $this->actingAs($gestion)->put("/articles/{$onduleur->id}", [
            'reference' => 'OND', 'nom' => 'Onduleur', 'est_serialise' => false, 'fabricant_id' => $fabricant->id,
        ])->assertSessionHas('alerte');
        $this->assertTrue($onduleur->fresh()->est_serialise);
    }

    public function test_rg17_rg18_regles_de_l_espace_administrateur(): void
    {
        $admin = $this->utilisateur(PermissionSeeder::ADMINISTRATEUR);
        $autre = $this->utilisateur('Lecteur');

        // RG18 : pas d'auto-modification des rôles.
        $this->actingAs($admin)->put("/admin/utilisateurs/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'actif' => true, 'roles' => ['Lecteur'],
        ])->assertSessionHas('alerte');
        $this->assertTrue($admin->fresh()->hasRole(PermissionSeeder::ADMINISTRATEUR));

        // Promotion d'un second administrateur, puis RG17 sur le dernier restant.
        $this->actingAs($admin)->put("/admin/utilisateurs/{$autre->id}", [
            'name' => $autre->name, 'email' => $autre->email, 'actif' => true, 'roles' => [PermissionSeeder::ADMINISTRATEUR],
        ])->assertSessionHas('succes');
        $this->actingAs($autre)->put("/admin/utilisateurs/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'actif' => false, 'roles' => [PermissionSeeder::ADMINISTRATEUR],
        ])->assertSessionHas('succes');
        $this->actingAs($admin)->put("/admin/utilisateurs/{$autre->id}", [
            'name' => $autre->name, 'email' => $autre->email, 'actif' => true, 'roles' => ['Lecteur'],
        ])->assertSessionHas('alerte');
    }
}
