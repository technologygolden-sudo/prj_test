<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sites et emplacements virtuels.
 *  - Entrepôt d'Abidjan, partagé : un espace GREENO et un espace TEVIA, chacun avec
 *    Réception, Entrepôt, Lab, Shop Abidjan et SAV retour.
 *  - Centres de service : Shop (prêt à la vente), Retour SAV et Défectueux, partagés entre propriétaires.
 * Rejouable : n'ajoute que ce qui manque.
 */
class SiteSeeder extends Seeder
{
    public const ENTREPOT = 'ENT-ABJ';

    /** type => [nom, vente] */
    public const EMPLACEMENTS_ENTREPOT = [
        'reception' => ['Réception', false],
        'entrepot' => ['Entrepôt', false],
        'lab' => ['Lab (réparation, reconditionnement)', false],
        'shop' => ['Shop Abidjan', true],
        'sav' => ['SAV retour', false],
    ];

    public const EMPLACEMENTS_CENTRE = [
        'vente' => ['Shop (prêt à la vente)', true],
        'retour' => ['Retour SAV', false],
        'defectueux' => ['Défectueux', false],
    ];

    public const CENTRES = ['Guiglo', 'Aboisso', 'Divo', 'Agboville', 'Abengourou', 'Duekoué', 'Grand Lahou', 'Bloléquin', 'Soubré', 'Yamoussoukro', 'Séguéla', 'Issia'];

    public function run(): void
    {
        $entrepot = $this->site(self::ENTREPOT, 'Entrepôt Abidjan', 'entrepot', 'Abidjan');
        foreach (['GREENO', 'TEVIA'] as $espace) {
            foreach (self::EMPLACEMENTS_ENTREPOT as $type => [$nom, $vente]) {
                $this->emplacement($entrepot, $espace, $type, "{$nom} {$espace}", $vente);
            }
        }

        foreach (self::CENTRES as $ville) {
            $code = 'CS-'.Str::upper(Str::slug(Str::ascii($ville), ''));
            $site = $this->site(substr($code, 0, 32), "Centre de service {$ville}", 'centre_service', $ville);
            foreach (self::EMPLACEMENTS_CENTRE as $type => [$nom, $vente]) {
                $this->emplacement($site, null, $type, $nom, $vente);
            }
        }

        // Stock antérieur aux emplacements : rangé dans l'entrepôt de son propriétaire.
        foreach (['unite_article' => 'en_stock', 'kit_assemble' => 'assemble'] as $table => $statut) {
            foreach (['GREENO', 'TEVIA'] as $espace) {
                $cible = DB::table('emplacement')->where(['site_id' => $entrepot, 'espace' => $espace, 'type' => 'entrepot'])->value('id');
                DB::table($table)->whereNull('emplacement_id')->where('statut', $statut)->where('proprietaire', $espace)
                    ->update(['emplacement_id' => $cible]);
            }
        }
    }

    private function site(string $code, string $nom, string $type, string $ville): int
    {
        DB::table('site')->insertOrIgnore(['code' => $code, 'nom' => $nom, 'type' => $type, 'ville' => $ville, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('site')->where('code', $code)->value('id');
    }

    private function emplacement(int $site, ?string $espace, string $type, string $nom, bool $vente): void
    {
        $existe = DB::table('emplacement')->where(['site_id' => $site, 'type' => $type])
            ->when($espace, fn ($q) => $q->where('espace', $espace), fn ($q) => $q->whereNull('espace'))->exists();
        if (! $existe) {
            DB::table('emplacement')->insert([
                'site_id' => $site, 'espace' => $espace, 'type' => $type, 'nom' => $nom, 'vente' => $vente,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
