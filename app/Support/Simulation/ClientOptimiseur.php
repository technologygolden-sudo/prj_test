<?php

namespace App\Support\Simulation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Appel du service Python d'optimisation (dossier v2, section 5.4). */
class ClientOptimiseur
{
    /**
     * @param  list<array{id: int, demande: int, rang: int, composition: array<int, int>}>  $kits
     * @param  array<int, int>  $stock
     * @return array{statut: string, optimalite: string, quantites: array<int, int>, duree_ms: int}
     */
    public function resoudre(string $mode, array $kits, array $stock): array
    {
        $corps = [
            'mode' => $mode,
            'kits' => array_map(fn ($k) => [
                'id' => (string) $k['id'],
                'demande' => $k['demande'],
                'rang' => $k['rang'],
                'composition' => array_map('intval', array_combine(array_map('strval', array_keys($k['composition'])), $k['composition'])),
            ], $kits),
            'stock' => (object) array_combine(array_map('strval', array_keys($stock)), array_map('intval', $stock)),
        ];

        try {
            $reponse = Http::withToken((string) config('services.optimiseur.jeton'))
                ->timeout(8)->acceptJson()
                ->post(rtrim((string) config('services.optimiseur.url'), '/').'/resoudre', $corps);
        } catch (ConnectionException) {
            throw new RuntimeException('Le service d’optimisation ne répond pas. Vérifiez qu’il est lancé.');
        }

        if (! $reponse->successful()) {
            throw new RuntimeException("Le service d’optimisation a refusé la demande (HTTP {$reponse->status()}).");
        }

        $r = $reponse->json();

        return [
            'statut' => $r['statut'],
            'optimalite' => $r['optimalite'],
            'quantites' => collect($r['quantites'] ?? [])->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q])->all(),
            'duree_ms' => (int) $r['duree_ms'],
        ];
    }
}
