<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mouvements de stock (RG24) : chaque variation passe par un mouvement daté et signé,
 * et met à jour dans la même transaction la quantité restante et le statut de l'unité.
 * À appeler à l'intérieur d'une transaction, l'unité étant verrouillée (SELECT … FOR UPDATE).
 */
class Stock
{
    /** @param array<string, mixed> $liens reception_id, sortie_id, kit_id */
    public static function mouvementUnite(object $unite, string $type, int $quantite, int $utilisateurId, ?string $motif = null, array $liens = []): object
    {
        $restante = $unite->quantite_restante + $quantite;
        if ($restante < 0 || $restante > $unite->quantite_initiale) {
            throw ValidationException::withMessages([
                'quantite' => "Quantité impossible pour {$unite->uid} : il reste {$unite->quantite_restante} sur {$unite->quantite_initiale}.",
            ]);
        }

        DB::table('mouvement_stock')->insert([
            'type' => $type,
            'quantite' => $quantite,
            'unite_id' => $unite->id,
            'motif' => $motif,
            'utilisateur_id' => $utilisateurId,
            'reception_id' => $liens['reception_id'] ?? null,
            'sortie_id' => $liens['sortie_id'] ?? null,
        ]);

        $statut = $unite->statut;
        if ($unite->nature === 'lot' && in_array($statut, ['en_stock', 'epuise'], true)) {
            $statut = $restante === 0 ? 'epuise' : 'en_stock';
        }

        DB::table('unite_article')->where('id', $unite->id)->update([
            'quantite_restante' => $restante,
            'statut' => $liens['statut'] ?? $statut,
            'updated_at' => now(),
        ]);

        return DB::table('unite_article')->where('id', $unite->id)->first();
    }

    public static function verrouillerUnite(int $id): ?object
    {
        return DB::table('unite_article')->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * Retrouve une unité à partir d'un code scanné : UID (QR, ou Code 128 d'un lot) ou n° de série (Code 128).
     *
     * @param  list<int>|null  $articles  limite la recherche par n° de série à ces articles
     * @return list<object>
     */
    public static function trouverUnites(string $code, ?array $articles = null): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }
        $parUid = DB::table('unite_article')->where('uid', strtoupper($code))->get()->all();
        if ($parUid) {
            return $parUid;
        }

        return DB::table('unite_article')
            ->where('numero_serie', $code)
            ->when($articles !== null, fn ($q) => $q->whereIn('article_id', $articles))
            ->get()->all();
    }
}
