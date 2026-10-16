<?php

namespace App\Support\Simulation;

use Illuminate\Support\Facades\DB;

/**
 * Calculs de la section 5.1 du dossier v2 :
 * capacité d'un kit seul et article limitant, besoin B, manquant M, consommé et reliquat R.
 */
class Calculateur
{
    /** @return array<int, array<int, int>> version_id => [article_id => quantité] */
    public static function compositions(array $versionIds): array
    {
        $compositions = [];
        foreach (DB::table('composition')->whereIn('version_kit_id', $versionIds)->get() as $ligne) {
            $compositions[$ligne->version_kit_id][$ligne->article_id] = (int) $ligne->quantite;
        }

        return $compositions;
    }

    /** Stock réel en lecture seule : unités en stock, quantité restante par article. */
    public static function stockReel(array $articleIds): array
    {
        return DB::table('unite_article')->where('statut', 'en_stock')->whereIn('article_id', $articleIds)
            ->groupBy('article_id')->selectRaw('article_id, sum(quantite_restante) as total')
            ->pluck('total', 'article_id')->map(fn ($q) => (int) $q)->all();
    }

    /**
     * @param  array<int, int>  $composition
     * @param  array<int, int>  $stock
     * @return array{capacite: int, limitant: ?int}
     */
    public static function capacite(array $composition, array $stock): array
    {
        $capacite = null;
        $limitant = null;
        foreach ($composition as $article => $quantite) {
            $possible = intdiv($stock[$article] ?? 0, $quantite);
            if ($capacite === null || $possible < $capacite) {
                $capacite = $possible;
                $limitant = $article;
            }
        }

        return ['capacite' => $capacite ?? 0, 'limitant' => $limitant];
    }

    /**
     * @param  array<int, array<int, int>>  $compositions
     * @param  array<int, int>  $quantites  version_id => quantité (voulue ou réalisable)
     * @return array<int, int> article_id => besoin
     */
    public static function besoins(array $compositions, array $quantites): array
    {
        $besoins = [];
        foreach ($compositions as $version => $composition) {
            foreach ($composition as $article => $quantite) {
                $besoins[$article] = ($besoins[$article] ?? 0) + $quantite * ($quantites[$version] ?? 0);
            }
        }

        return $besoins;
    }
}
