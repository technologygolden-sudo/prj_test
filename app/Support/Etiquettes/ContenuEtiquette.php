<?php

namespace App\Support\Etiquettes;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Contenu d'une étiquette selon son type (dossier v2, section 6.1).
 * Le nom de société et le n° SAV sont lus par le serveur au moment d'imprimer (RG15).
 */
final class ContenuEtiquette
{
    public function __construct(
        public readonly string $cible,       // article | lot | kit
        public readonly string $societe,
        public readonly string $sav,
        public readonly string $libelle,
        public readonly string $reference,
        public readonly string $codeBarres,  // Code 128
        public readonly string $qr,          // toujours l'UID
        public readonly string $pied,
    ) {}

    public static function pourUnite(int $uniteId): self
    {
        $u = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->where('unite_article.id', $uniteId)
            ->first(['unite_article.*', 'article.nom', 'article.reference']);
        if (! $u) {
            throw new InvalidArgumentException("Unité {$uniteId} introuvable.");
        }
        [$societe, $sav] = self::societe();

        if ($u->nature === 'serie') {
            return new self('article', $societe, $sav, $u->nom, "Réf. {$u->reference}", $u->numero_serie, $u->uid, "S/N {$u->numero_serie}");
        }

        // Code 128 = UID pour distinguer deux lots du même article à l'assemblage.
        return new self('lot', $societe, $sav, $u->nom, "Réf. {$u->reference}", $u->uid, $u->uid, "LOT {$u->quantite_restante}");
    }

    public static function pourKit(int $kitId): self
    {
        $k = DB::table('kit_assemble')
            ->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
            ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->where('kit_assemble.id', $kitId)
            ->first(['kit_assemble.*', 'type_kit.nom', 'type_kit.reference', 'type_kit.partno']);
        if (! $k) {
            throw new InvalidArgumentException("Kit {$kitId} introuvable.");
        }
        [$societe, $sav] = self::societe();

        return new self('kit', $societe, $sav, $k->nom, "Kit · {$k->reference} / {$k->partno}", $k->numero_serie, $k->uid, "S/N {$k->numero_serie}");
    }

    /** @return array{0: string, 1: string} */
    private static function societe(): array
    {
        $p = DB::table('parametre_societe')->where('id', 1)->first();

        return [$p->nom_societe ?? '', $p ? "SAV {$p->num_sav}" : ''];
    }
}
