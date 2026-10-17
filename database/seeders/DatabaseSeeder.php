<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([PermissionSeeder::class, SiteSeeder::class, CategorieSeeder::class, ListeValeurSeeder::class]);

        // RG15 : valeurs initiales, modifiables ensuite par l'administrateur uniquement.
        DB::table('parametre_societe')->insertOrIgnore([
            'id' => 1,
            'nom_societe' => 'GREENO',
            'num_sav' => '07 06 24 87 24',
        ]);

        // Modèles d'étiquette 50 × 30 mm (dossier v2, section 6) : dimensions et résolution réglables, jamais de ZPL libre.
        foreach (['article', 'lot', 'kit'] as $cible) {
            foreach (['ZPL', 'PDF'] as $format) {
                DB::table('modele_etiquette')->insertOrIgnore([
                    'code' => strtolower("{$cible}-{$format}-50x30"), 'cible' => $cible, 'format' => $format,
                    'largeur_mm' => 50, 'hauteur_mm' => 30, 'dpi' => 203, 'gabarit' => 'standard-v2',
                    'actif' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
