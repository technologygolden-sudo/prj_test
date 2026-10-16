<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emplacements virtuels, propriété et état des unités et des kits.
 *
 * RG28 : un élément défectueux n'entre jamais dans un emplacement de vente (contrôlé aussi en base).
 * RG29 : un espace GREENO ou TEVIA ne reçoit que le stock de son propriétaire (contrôlé aussi en base).
 * RG30 : un déplacement interne exige un motif ; un passage d'un site à l'autre passe par un transfert.
 * RG31 : seuls les shops (emplacements de vente) expédient vers un client.
 * RG32 : le SAV déclare les défauts ; le Lab répare ou reconditionne, dans un emplacement Lab.
 *
 * À appeler dans une transaction.
 */
class Emplacements
{
    /** @return object{table: string, colonne: string, ligne: object} */
    public static function verrouiller(string $type, int $id): object
    {
        $table = $type === 'kit' ? 'kit_assemble' : 'unite_article';
        $ligne = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        if (! $ligne) {
            throw ValidationException::withMessages(['cible' => 'Élément introuvable.']);
        }

        return (object) ['table' => $table, 'colonne' => $type === 'kit' ? 'kit_id' : 'unite_id', 'ligne' => $ligne, 'type' => $type];
    }

    public static function emplacement(int $id): object
    {
        $e = DB::table('emplacement')->join('site', 'site.id', '=', 'emplacement.site_id')
            ->where('emplacement.id', $id)
            ->first(['emplacement.*', 'site.nom as site_nom', 'site.type as site_type', 'site.actif as site_actif']);
        if (! $e || ! $e->actif || ! $e->site_actif) {
            throw ValidationException::withMessages(['emplacement_id' => 'Emplacement inconnu ou désactivé.']);
        }

        return $e;
    }

    /** Statut « disponible » attendu selon la nature de l'élément. */
    public static function disponible(object $cible): bool
    {
        return $cible->type === 'kit' ? $cible->ligne->statut === 'assemble' : $cible->ligne->statut === 'en_stock';
    }

    /**
     * Déplacement interne à un site (RG30). Pour un lot, une quantité partielle crée un nouveau lot (nouvel UID).
     *
     * @return int identifiant de l'élément déplacé (le nouveau lot en cas de scission)
     */
    public static function deplacer(string $type, int $id, int $versId, int $userId, string $motif, ?int $quantite = null): int
    {
        $cible = self::verrouiller($type, $id);
        $vers = self::emplacement($versId);
        $de = $cible->ligne->emplacement_id ? self::emplacement($cible->ligne->emplacement_id) : null;

        if (! self::disponible($cible) || ! $de) {
            throw ValidationException::withMessages(['cible' => 'Seul un élément disponible, rangé dans un emplacement, peut être déplacé.']);
        }
        if ($de->site_id !== $vers->site_id) {
            throw ValidationException::withMessages(['emplacement_id' => 'Pour changer de site, créez un transfert (RG30).']);
        }
        if ($de->id === $vers->id) {
            throw ValidationException::withMessages(['emplacement_id' => 'L’élément est déjà dans cet emplacement.']);
        }
        if ($vers->espace && $vers->espace !== $cible->ligne->proprietaire) {
            throw ValidationException::withMessages(['emplacement_id' => "Cet emplacement est réservé au stock {$vers->espace} : utilisez une cession (RG29)."]);
        }
        self::controlerVente($cible->ligne, $vers);

        $id = self::scinderSiBesoin($cible, $quantite, $userId, $motif);
        DB::table($cible->table)->where('id', $id)->update(['emplacement_id' => $vers->id, 'updated_at' => now()]);
        self::tracer('deplacement', $cible->colonne, $id, $de->id, $vers->id, $userId, $motif);

        return $id;
    }

    /** Cession GREENO ↔ TEVIA dans un même site : l'élément change de propriétaire et d'espace. */
    public static function ceder(string $type, int $id, int $versId, int $userId, string $motif): void
    {
        $cible = self::verrouiller($type, $id);
        $vers = self::emplacement($versId);
        $de = $cible->ligne->emplacement_id ? self::emplacement($cible->ligne->emplacement_id) : null;

        if (! self::disponible($cible) || ! $de || $de->site_id !== $vers->site_id) {
            throw ValidationException::withMessages(['emplacement_id' => 'La cession se fait vers un emplacement du même site, pour un élément disponible.']);
        }
        if (! $vers->espace || $vers->espace === $cible->ligne->proprietaire) {
            throw ValidationException::withMessages(['emplacement_id' => 'Choisissez un emplacement de l’autre espace (GREENO ou TEVIA).']);
        }
        self::controlerVente($cible->ligne, $vers);

        DB::table($cible->table)->where('id', $id)->update(['proprietaire' => $vers->espace, 'emplacement_id' => $vers->id, 'updated_at' => now()]);
        if ($type === 'kit') {
            DB::table('unite_article')->whereIn('id', DB::table('contenu_kit')->where('kit_id', $id)->pluck('unite_id'))
                ->update(['proprietaire' => $vers->espace]);
        }
        self::tracer('cession', $cible->colonne, $id, $de->id, $vers->id, $userId, "{$cible->ligne->proprietaire} → {$vers->espace} : {$motif}");
    }

    /** RG32 : le SAV déclare un défaut ; l'élément quitte tout emplacement de vente. */
    public static function declarerDefaut(string $type, int $id, int $userId, string $description, ?int $versId): void
    {
        $cible = self::verrouiller($type, $id);
        if (! self::disponible($cible)) {
            throw ValidationException::withMessages(['cible' => 'Seul un élément disponible peut être déclaré défectueux.']);
        }
        if ($cible->ligne->etat === 'defectueux') {
            throw ValidationException::withMessages(['cible' => 'Cet élément est déjà déclaré défectueux.']);
        }
        $de = $cible->ligne->emplacement_id ? self::emplacement($cible->ligne->emplacement_id) : null;
        $vers = $versId ? self::emplacement($versId) : $de;
        if (! $vers || $vers->vente) {
            throw ValidationException::withMessages(['emplacement_id' => 'Un élément défectueux doit quitter le shop : choisissez SAV retour, Retour SAV ou Défectueux (RG28).']);
        }
        if ($de && $vers->site_id !== $de->site_id) {
            throw ValidationException::withMessages(['emplacement_id' => 'Choisissez un emplacement du même site.']);
        }

        DB::table($cible->table)->where('id', $id)->update(['etat' => 'defectueux', 'emplacement_id' => $vers->id, 'updated_at' => now()]);
        DB::table('declaration_etat')->insert(['type' => 'defaut', $cible->colonne => $id, 'description' => $description, 'utilisateur_id' => $userId]);
        if ($de && $de->id !== $vers->id) {
            self::tracer('deplacement', $cible->colonne, $id, $de->id, $vers->id, $userId, "Défaut déclaré : {$description}");
        }
    }

    /** RG32 : le Lab remet en état (réparation ou reconditionnement) un élément présent au Lab. */
    public static function declarerReparation(string $type, int $id, int $userId, string $nature, string $description): void
    {
        $cible = self::verrouiller($type, $id);
        $ici = $cible->ligne->emplacement_id ? self::emplacement($cible->ligne->emplacement_id) : null;
        if (! self::disponible($cible) || ! $ici || $ici->type !== 'lab') {
            throw ValidationException::withMessages(['cible' => 'La réparation se déclare sur un élément présent au Lab : déplacez-le d’abord au Lab.']);
        }
        if ($nature === 'reparation' && $cible->ligne->etat !== 'defectueux') {
            throw ValidationException::withMessages(['cible' => 'Cet élément n’est pas déclaré défectueux : choisissez « reconditionnement ».']);
        }

        DB::table($cible->table)->where('id', $id)->update(['etat' => 'bon', 'updated_at' => now()]);
        DB::table('declaration_etat')->insert(['type' => $nature, $cible->colonne => $id, 'description' => $description, 'utilisateur_id' => $userId]);
    }

    /** RG28 : contrôle applicatif avant la contrainte en base, pour un message clair. */
    public static function controlerVente(object $ligne, object $vers): void
    {
        if ($ligne->etat === 'defectueux' && $vers->vente) {
            throw ValidationException::withMessages(['emplacement_id' => 'Un élément défectueux ne peut pas aller dans un shop tant qu’il n’est pas réparé (RG28).']);
        }
    }

    /** Scission d'un lot : la quantité demandée part dans un nouveau lot (nouvel UID, étiquette à imprimer). */
    public static function scinderSiBesoin(object $cible, ?int $quantite, int $userId, string $motif): int
    {
        $ligne = $cible->ligne;
        if ($cible->type === 'kit' || $ligne->nature !== 'lot' || $quantite === null || $quantite >= $ligne->quantite_restante) {
            return $ligne->id;
        }
        if ($quantite < 1) {
            throw ValidationException::withMessages(['quantite' => 'Quantité invalide.']);
        }

        $nouveau = DB::table('unite_article')->insertGetId([
            'uid' => Uid::generer(Uid::LOT), 'nature' => 'lot', 'numero_serie' => null,
            'quantite_initiale' => $quantite, 'quantite_restante' => 0, 'statut' => 'en_stock',
            'emplacement_id' => $ligne->emplacement_id, 'article_id' => $ligne->article_id, 'reception_id' => $ligne->reception_id,
            'proprietaire' => $ligne->proprietaire, 'etat' => $ligne->etat, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Stock::mouvementUnite($ligne, 'scission', -$quantite, $userId, "Vers le lot {$nouveau} : {$motif}");
        Stock::mouvementUnite(Stock::verrouillerUnite($nouveau), 'scission', $quantite, $userId, "Depuis le lot {$ligne->id} : {$motif}");

        return $nouveau;
    }

    public static function tracer(string $type, string $colonne, int $id, ?int $de, ?int $vers, int $userId, ?string $motif = null, ?int $transfert = null): void
    {
        DB::table('mouvement_emplacement')->insert([
            'type' => $type, $colonne => $id, 'de_emplacement_id' => $de, 'vers_emplacement_id' => $vers,
            'transfert_id' => $transfert, 'motif' => $motif, 'utilisateur_id' => $userId,
        ]);
    }

    /** Liste des emplacements actifs, pour les écrans. */
    public static function liste(?int $siteId = null)
    {
        $siteId ??= Perimetre::site(); // un shop manager ne voit que les emplacements de son site

        return DB::table('emplacement')->join('site', 'site.id', '=', 'emplacement.site_id')
            ->where('emplacement.actif', true)->where('site.actif', true)
            ->when($siteId, fn ($q) => $q->where('site.id', $siteId))
            ->orderByRaw("site.type = 'entrepot' desc")->orderBy('site.nom')->orderBy('emplacement.espace')->orderBy('emplacement.id')
            ->get(['emplacement.id', 'emplacement.nom', 'emplacement.type', 'emplacement.espace', 'emplacement.vente', 'site.id as site_id', 'site.nom as site'])
            ->map(fn ($e) => (array) $e + ['libelle' => "{$e->site} › {$e->nom}"]);
    }
}
