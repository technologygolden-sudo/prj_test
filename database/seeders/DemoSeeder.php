<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Données de démonstration pour un poste de développement uniquement :
 *   php artisan db:seed --class=DemoSeeder
 *
 * Comptes de test (mot de passe commun : Demo-Kits-2026) :
 *   admin.demo   Administrateur
 *   gestion.demo Gestionnaire référentiel
 *   lecteur.demo Lecteur
 */
class DemoSeeder extends Seeder
{
    public const MOT_DE_PASSE = 'Demo-Kits-2026';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder est réservé aux environnements local et testing.');
        }

        $this->call(DatabaseSeeder::class);

        foreach ([
            'admin.demo' => ['Admin Démo', PermissionSeeder::ADMINISTRATEUR],
            'gestion.demo' => ['Gestionnaire Démo', 'Gestionnaire référentiel'],
            'lecteur.demo' => ['Lecteur Démo', 'Lecteur'],
        ] as $login => [$nom, $role]) {
            $user = User::firstOrCreate(
                ['login' => $login],
                ['name' => $nom, 'email' => "{$login}@exemple.test", 'password' => self::MOT_DE_PASSE, 'actif' => true],
            );
            $user->syncRoles([$role]);
        }

        // Exemple 5.3 du dossier de conception : kits A et B.
        $categorie = DB::table('categorie')->insertGetId(['libelle' => 'Solaire']);
        $fabricant = DB::table('fabricant')->insertGetId(['nom' => 'Huawei']);
        $article = fn (string $ref, string $nom, bool $serie) => DB::table('article')->insertGetId([
            'reference' => $ref, 'nom' => $nom, 'est_serialise' => $serie,
            'categorie_id' => $categorie, 'fabricant_id' => $fabricant,
        ]);
        $onduleur = $article('OND-5K', 'Onduleur 5 kW', true);
        $panneau = $article('PAN-400', 'Panneau 400 W', false);
        $coffret = $article('COF-AC', 'Coffret AC', false);
        $batterie = $article('BAT-5', 'Batterie 5 kWh', true);

        DB::transaction(function () use ($onduleur, $panneau, $coffret, $batterie, $categorie, $fabricant) {
            foreach ([
                ['KIT-A', 'PN-A-01', 'Kit A résidentiel', [$panneau => 4, $coffret => 1]],
                ['KIT-B', 'PN-B-01', 'Kit B stockage', [$batterie => 1, $panneau => 2]],
            ] as [$ref, $pn, $nom, $lignes]) {
                $type = DB::table('type_kit')->insertGetId([
                    'reference' => $ref, 'partno' => $pn, 'nom' => $nom, 'categorie_id' => $categorie, 'fabricant_id' => $fabricant,
                ]);
                $version = DB::table('version_kit')->insertGetId(['type_kit_id' => $type, 'numero' => 1, 'article_porteur_id' => $onduleur]);
                DB::table('composition')->insert(['version_kit_id' => $version, 'article_id' => $onduleur, 'quantite' => 1]);
                foreach ($lignes as $id => $quantite) {
                    DB::table('composition')->insert(['version_kit_id' => $version, 'article_id' => $id, 'quantite' => $quantite]);
                }
                if ($ref === 'KIT-A') {
                    DB::table('version_kit')->where('id', $version)->update(['verrouillee' => true, 'verrouillee_le' => now()]);
                }
            }
        });
    }
}
