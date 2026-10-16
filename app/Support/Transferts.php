<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transferts entre sites (entrepôt ↔ centres de service) : envoi « en transit »,
 * puis réception validée par le site d'arrivée, élément par élément.
 * À appeler dans une transaction.
 */
class Transferts
{
    /**
     * @param  list<array{type: string, id: int, quantite?: int|null}>  $cibles
     */
    public static function envoyer(int $origine, int $destination, string $motif, array $cibles, int $userId): int
    {
        if ($origine === $destination) {
            throw ValidationException::withMessages(['site_destination_id' => 'Le site d’arrivée doit être différent du site de départ.']);
        }

        $transfert = DB::table('transfert')->insertGetId([
            'site_origine_id' => $origine, 'site_destination_id' => $destination, 'statut' => 'en_transit',
            'motif' => $motif, 'envoye_par' => $userId, 'envoye_le' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($cibles as $c) {
            $cible = Emplacements::verrouiller($c['type'], (int) $c['id']);
            $de = $cible->ligne->emplacement_id ? Emplacements::emplacement($cible->ligne->emplacement_id) : null;
            if (! Emplacements::disponible($cible) || ! $de || $de->site_id !== $origine) {
                throw ValidationException::withMessages(['cibles' => 'Un élément de la liste n’est pas disponible sur le site de départ.']);
            }

            $id = Emplacements::scinderSiBesoin($cible, isset($c['quantite']) ? (int) $c['quantite'] : null, $userId, "Transfert n° {$transfert}");
            DB::table($cible->table)->where('id', $id)->update(['statut' => 'en_transit', 'emplacement_id' => null, 'updated_at' => now()]);
            DB::table('transfert_ligne')->insert(['transfert_id' => $transfert, $cible->colonne => $id, 'emplacement_depart_id' => $de->id]);
            Emplacements::tracer('envoi', $cible->colonne, $id, $de->id, null, $userId, $motif, $transfert);
        }

        Audit::enregistrer('envoyer', 'transfert', apres: ['id' => $transfert, 'origine' => $origine, 'destination' => $destination, 'lignes' => count($cibles), 'motif' => $motif], utilisateurId: $userId);

        return $transfert;
    }

    /**
     * Réception par le site d'arrivée : chaque ligne reçue est rangée dans un emplacement de ce site.
     *
     * @param  array<int, int>  $arrivees  transfert_ligne.id => emplacement.id
     */
    public static function recevoir(int $transfertId, array $arrivees, int $userId): string
    {
        $transfert = DB::table('transfert')->where('id', $transfertId)->lockForUpdate()->first();
        if (! $transfert || $transfert->statut === 'recu') {
            throw ValidationException::withMessages(['lignes' => 'Ce transfert est déjà entièrement reçu.']);
        }

        foreach ($arrivees as $ligneId => $emplacementId) {
            $ligne = DB::table('transfert_ligne')->where(['id' => $ligneId, 'transfert_id' => $transfertId])->whereNull('recu_le')->first();
            if (! $ligne) {
                throw ValidationException::withMessages(['lignes' => 'Une ligne est inconnue ou déjà reçue.']);
            }
            $vers = Emplacements::emplacement((int) $emplacementId);
            if ($vers->site_id !== $transfert->site_destination_id) {
                throw ValidationException::withMessages(['lignes' => 'Rangez les éléments dans un emplacement du site d’arrivée.']);
            }

            $type = $ligne->kit_id ? 'kit' : 'unite';
            $cible = Emplacements::verrouiller($type, $ligne->kit_id ?? $ligne->unite_id);
            if ($vers->espace && $vers->espace !== $cible->ligne->proprietaire) {
                throw ValidationException::withMessages(['lignes' => "Un élément {$cible->ligne->proprietaire} ne peut pas être rangé dans l’espace {$vers->espace} (RG29)."]);
            }
            Emplacements::controlerVente($cible->ligne, $vers);

            DB::table($cible->table)->where('id', $cible->ligne->id)->update([
                'statut' => $type === 'kit' ? 'assemble' : 'en_stock', 'emplacement_id' => $vers->id, 'updated_at' => now(),
            ]);
            DB::table('transfert_ligne')->where('id', $ligneId)->update(['emplacement_arrivee_id' => $vers->id, 'recu_le' => now(), 'recu_par' => $userId]);
            Emplacements::tracer('reception', $cible->colonne, $cible->ligne->id, null, $vers->id, $userId, null, $transfertId);
        }

        $reste = DB::table('transfert_ligne')->where('transfert_id', $transfertId)->whereNull('recu_le')->count();
        $statut = $reste === 0 ? 'recu' : 'recu_partiel';
        DB::table('transfert')->where('id', $transfertId)->update(['statut' => $statut, 'recu_par' => $userId, 'recu_le' => now(), 'updated_at' => now()]);
        Audit::enregistrer('recevoir', 'transfert', apres: ['id' => $transfertId, 'lignes_recues' => count($arrivees), 'statut' => $statut], utilisateurId: $userId);

        return $statut;
    }
}
