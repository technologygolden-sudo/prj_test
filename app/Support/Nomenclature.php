<?php

namespace App\Support;

use App\Models\VersionKit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Enregistrement d'une nomenclature de version de kit (RG03, RG05).
 * Le porteur de série, s'il est choisi, est inscrit avec la quantité 1 ; les lignes portent les autres articles.
 * Un brouillon peut attendre son porteur, qui devient obligatoire au verrouillage.
 */
class Nomenclature
{
    /** @return array<string, mixed> */
    public static function regles(): array
    {
        return [
            'porteur_id' => ['nullable', 'required_without:lignes', 'integer', Rule::exists('article', 'id')->where('est_serialise', true)],
            'lignes' => ['nullable', 'array', 'max:500'],
            'lignes.*.article_id' => ['required', 'integer', 'distinct', 'different:porteur_id', Rule::exists('article', 'id')],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /** @param array<int, array{article_id: int, quantite: int}> $lignes */
    public static function enregistrer(VersionKit $version, ?int $porteurId, array $lignes): void
    {
        DB::transaction(function () use ($version, $porteurId, $lignes) {
            DB::table('composition')->where('version_kit_id', $version->id)->delete();
            $version->update(['article_porteur_id' => $porteurId]);

            $rangees = $porteurId ? [['version_kit_id' => $version->id, 'article_id' => $porteurId, 'quantite' => 1]] : [];
            foreach ($lignes as $ligne) {
                if ((int) $ligne['article_id'] !== $porteurId) {
                    $rangees[] = ['version_kit_id' => $version->id, 'article_id' => (int) $ligne['article_id'], 'quantite' => (int) $ligne['quantite']];
                }
            }
            if ($rangees) {
                DB::table('composition')->insert($rangees);
            }
        });
    }

    /** @return array<string, mixed> */
    public static function instantane(VersionKit $version): array
    {
        return [
            'numero' => $version->numero,
            'porteur_id' => $version->article_porteur_id,
            'composition' => DB::table('composition')->where('version_kit_id', $version->id)
                ->orderBy('article_id')->pluck('quantite', 'article_id')->all(),
        ];
    }
}
