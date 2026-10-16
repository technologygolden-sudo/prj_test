<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Catégories de référence ; d'autres peuvent être ajoutées dans Référentiel › Catégories. Rejouable. */
class CategorieSeeder extends Seeder
{
    public const CATEGORIES = ['SHS Small', 'SHS Mini', 'SHS Medium', 'SHS Large', 'CNI', 'Pompe de surface', 'Pompe immergée'];

    public function run(): void
    {
        foreach (self::CATEGORIES as $libelle) {
            DB::table('categorie')->insertOrIgnore(['libelle' => $libelle, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
