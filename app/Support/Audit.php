<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Journal d'audit en ajout seul (ENF05, RG20) : qui, quoi, quand, adresse IP, avant/après.
 */
class Audit
{
    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    public static function enregistrer(
        string $action,
        Model|string $objet,
        ?array $avant = null,
        ?array $apres = null,
        ?int $utilisateurId = null,
    ): void {
        DB::table('journal_audit')->insert([
            'utilisateur_id' => $utilisateurId ?? auth()->id(),
            'ip' => request()?->ip(),
            'action' => $action,
            'objet_type' => $objet instanceof Model ? $objet->getTable() : $objet,
            'objet_id' => $objet instanceof Model ? (string) $objet->getKey() : null,
            'valeur_avant' => $avant === null ? null : json_encode(self::nettoyer($avant), JSON_UNESCAPED_UNICODE),
            'valeur_apres' => $apres === null ? null : json_encode(self::nettoyer($apres), JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $valeurs
     * @return array<string, mixed>
     */
    private static function nettoyer(array $valeurs): array
    {
        unset($valeurs['password'], $valeurs['remember_token'], $valeurs['two_factor_secret'], $valeurs['two_factor_recovery_codes']);

        return $valeurs;
    }
}
