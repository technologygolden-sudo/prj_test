<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Périmètre de stock d'un utilisateur (RG33).
 *  - Rattaché à un centre de service (shop manager) : uniquement le stock de ce site.
 *  - Sans site, ou rattaché à l'entrepôt : visibilité globale.
 */
class Perimetre
{
    /** null = tous les sites. */
    public static function site(?User $user = null): ?int
    {
        $user ??= auth()->user();
        $siteId = $user?->getAttributes()['site_id'] ?? null;
        if (! $siteId) {
            return null;
        }

        return DB::table('site')->where('id', $siteId)->value('type') === 'entrepot' ? null : (int) $siteId;
    }

    public static function estGlobal(?User $user = null): bool
    {
        return self::site($user) === null;
    }

    /** Filtre une requête qui joint la table emplacement. */
    public static function filtrer($requete, string $colonneSite = 'emplacement.site_id')
    {
        $site = self::site();

        return $site ? $requete->where($colonneSite, $site) : $requete;
    }

    /** L'élément (unité ou kit) est-il dans le périmètre ? Un élément en transit compte pour ses deux sites. */
    public static function couvre(string $table, int $id): bool
    {
        $site = self::site();
        if ($site === null) {
            return true;
        }
        $ligne = DB::table($table)->where('id', $id)->first(['emplacement_id']);
        if ($ligne?->emplacement_id) {
            return DB::table('emplacement')->where('id', $ligne->emplacement_id)->value('site_id') === $site;
        }
        $colonne = $table === 'kit_assemble' ? 'kit_id' : 'unite_id';

        return DB::table('transfert_ligne')->join('transfert', 'transfert.id', '=', 'transfert_ligne.transfert_id')
            ->where("transfert_ligne.{$colonne}", $id)
            ->where(fn ($q) => $q->where('site_origine_id', $site)->orWhere('site_destination_id', $site))
            ->exists();
    }

    public static function exiger(string $table, int $id): void
    {
        if (! self::couvre($table, $id)) {
            throw ValidationException::withMessages(['cible' => 'Cet élément n’est pas dans le stock de votre site.']);
        }
    }

    /** Seul un utilisateur du site d'arrivée valide la réception d'un transfert (l'entrepôt pour l'entrepôt). */
    public static function peutRecevoir(int $siteDestination, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if ($user?->hasRole(\Database\Seeders\PermissionSeeder::ADMINISTRATEUR)) {
            return true; // l'administrateur peut réceptionner tous les transferts
        }
        $siteUser = $user?->getAttributes()['site_id'] ?? null;
        $destinationEntrepot = DB::table('site')->where('id', $siteDestination)->value('type') === 'entrepot';

        return $siteUser ? (int) $siteUser === $siteDestination : $destinationEntrepot;
    }
}
