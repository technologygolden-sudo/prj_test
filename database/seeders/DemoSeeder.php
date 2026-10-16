<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Uid;
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
 *   magasin.demo Réceptionnaire
 *   atelier.demo Assembleur
 *   planif.demo  Planificateur
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
            'magasin.demo' => ['Magasin Démo', 'Réceptionnaire'],
            'atelier.demo' => ['Atelier Démo', 'Assembleur'],
            'planif.demo' => ['Planif Démo', 'Planificateur'],
        ] as $login => [$nom, $role]) {
            $user = User::firstOrCreate(
                ['login' => $login],
                ['name' => $nom, 'email' => "{$login}@exemple.test", 'password' => self::MOT_DE_PASSE, 'actif' => true],
            );
            $user->syncRoles([$role]);
        }

        // Imprimantes de démonstration : ZPL écrit dans storage/app/impressions, PDF dans le navigateur.
        foreach ([
            ['nom' => 'ZD411 magasin (simulation)', 'adresse' => 'fichier:zd411-magasin', 'modele' => 'Zebra ZD411', 'dpi' => 203],
            ['nom' => 'Imprimante de bureau (PDF)', 'adresse' => 'navigateur', 'modele' => 'PDF', 'dpi' => 300],
        ] as $imprimante) {
            DB::table('imprimante')->insertOrIgnore($imprimante + ['actif' => true, 'created_at' => now(), 'updated_at' => now()]);
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
                DB::table('version_kit')->where('id', $version)->update(['verrouillee' => true, 'verrouillee_le' => now()]);
            }
        });

        // Stock de l'exemple 5.3 : 10 onduleurs, 30 panneaux, 6 coffrets, 5 batteries, reçus sur un BL de démonstration.
        $admin = User::where('login', 'admin.demo')->value('id');
        $reception = DB::table('reception')->insertGetId([
            'date' => now()->toDateString(), 'num_bl' => 'BL-DEMO-001', 'fournisseur' => 'Fournisseur démo', 'utilisateur_id' => $admin,
        ]);
        $entree = function (int $articleId, ?string $serie, int $quantite) use ($reception, $admin) {
            $id = DB::table('unite_article')->insertGetId([
                'uid' => Uid::generer($serie ? Uid::ARTICLE : Uid::LOT), 'nature' => $serie ? 'serie' : 'lot', 'numero_serie' => $serie,
                'quantite_initiale' => $quantite, 'quantite_restante' => $quantite, 'statut' => 'en_stock',
                'article_id' => $articleId, 'reception_id' => $reception,
            ]);
            DB::table('mouvement_stock')->insert([
                'type' => 'entree', 'quantite' => $quantite, 'unite_id' => $id, 'reception_id' => $reception, 'utilisateur_id' => $admin,
            ]);
        };
        for ($i = 1; $i <= 10; $i++) {
            $entree($onduleur, sprintf('OND-DEMO-%03d', $i), 1);
        }
        for ($i = 1; $i <= 5; $i++) {
            $entree($batterie, sprintf('BAT-DEMO-%03d', $i), 1);
        }
        $entree($panneau, null, 30);
        $entree($coffret, null, 6);
    }
}
