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
        $this->call(PermissionSeeder::class);

        // RG15 : valeurs initiales, modifiables ensuite par l'administrateur uniquement.
        DB::table('parametre_societe')->insertOrIgnore([
            'id' => 1,
            'nom_societe' => 'GREENO',
            'num_sav' => '07 06 24 87 24',
        ]);
    }
}
