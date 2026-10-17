<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Campagnes d'inventaire.
 *
 * RG35 : au lancement, le stock théorique est figé par site et par article ou type de kit du périmètre.
 * RG36 : le 1er comptage porte sur tout le périmètre ; un 2e comptage, décidé par le contrôleur de gestion,
 *        ne porte que sur les lignes qu'il désigne (en écart) ; le décompte final est arrêté par le contrôleur.
 * RG37 : chaque écart (final − théorique) est valorisé au coût unitaire figé au lancement.
 * RG38 : à la clôture, les écarts peuvent être appliqués au stock par des ajustements tracés ; les n° de série
 *        introuvables passent « Perdu », les n° hors système sont listés pour régularisation.
 */
class Inventaires
{
    public static function campagne(int $id, bool $verrouiller = false): object
    {
        $q = DB::table('inventaire')->where('id', $id);

        return ($verrouiller ? $q->lockForUpdate()->first() : $q->first()) ?? abort(404);
    }

    /** Articles du périmètre : tous, ou ceux des catégories et articles choisis. */
    public static function articles(object $inv): Collection
    {
        $cibles = DB::table('inventaire_cible')->where('inventaire_id', $inv->id)->get()->groupBy('type');

        return DB::table('article')
            ->when(! $inv->tous_articles, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('categorie_id', ($cibles['categorie'] ?? collect())->pluck('cible_id'))
                ->orWhereIn('id', ($cibles['article'] ?? collect())->pluck('cible_id'))))
            ->pluck('id');
    }

    /** Types de kit du périmètre : tous, ou ceux des catégories et types choisis. */
    public static function typesKit(object $inv): Collection
    {
        $cibles = DB::table('inventaire_cible')->where('inventaire_id', $inv->id)->get()->groupBy('type');

        return DB::table('type_kit')
            ->when(! $inv->tous_kits, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('categorie_id', ($cibles['categorie'] ?? collect())->pluck('cible_id'))
                ->orWhereIn('id', ($cibles['type_kit'] ?? collect())->pluck('cible_id'))))
            ->pluck('id');
    }

    /** Coût d'un type de kit : son coût s'il est renseigné, sinon la somme des coûts de sa composition. */
    public static function coutKit(int $typeKitId): ?float
    {
        $propre = DB::table('type_kit')->where('id', $typeKitId)->value('cout_unitaire');
        if ($propre !== null) {
            return (float) $propre;
        }
        $version = DB::table('version_kit')->where('type_kit_id', $typeKitId)->orderByDesc('verrouillee')->orderByDesc('numero')->value('id');
        if (! $version) {
            return null;
        }
        $total = DB::table('composition')->join('article', 'article.id', '=', 'composition.article_id')
            ->where('version_kit_id', $version)->selectRaw('sum(composition.quantite * article.cout_unitaire) as total, bool_and(article.cout_unitaire IS NOT NULL) as complet')->first();

        return $total->complet ? (float) $total->total : null;
    }

    /** RG35 : fige le théorique et ouvre le 1er comptage. */
    public static function lancer(int $id): void
    {
        $inv = self::campagne($id, true);
        if ($inv->statut !== 'brouillon') {
            throw ValidationException::withMessages(['statut' => 'Cette campagne est déjà lancée.']);
        }
        $sites = DB::table('inventaire_site')->where('inventaire_id', $id)->pluck('site_id');
        if ($sites->isEmpty() || (! $inv->tous_articles && ! $inv->tous_kits && ! DB::table('inventaire_cible')->where('inventaire_id', $id)->exists())) {
            throw ValidationException::withMessages(['statut' => 'Choisissez au moins un site et un périmètre (articles, kits ou catégories).']);
        }

        $articles = self::articles($inv);
        $couts = DB::table('article')->whereIn('id', $articles)->pluck('cout_unitaire', 'id');
        $stock = DB::table('unite_article')->join('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
            ->where('unite_article.statut', 'en_stock')->whereIn('emplacement.site_id', $sites)->whereIn('unite_article.article_id', $articles)
            ->groupBy('emplacement.site_id', 'unite_article.article_id')
            ->get(['emplacement.site_id', 'unite_article.article_id', DB::raw('sum(quantite_restante)::int as quantite')]);
        foreach ($stock as $s) {
            DB::table('inventaire_ligne')->insert([
                'inventaire_id' => $id, 'site_id' => $s->site_id, 'article_id' => $s->article_id,
                'theorique' => $s->quantite, 'cout_unitaire' => $couts[$s->article_id],
            ]);
        }

        $types = self::typesKit($inv);
        $kits = DB::table('kit_assemble')->join('emplacement', 'emplacement.id', '=', 'kit_assemble.emplacement_id')
            ->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
            ->where('kit_assemble.statut', 'assemble')->whereIn('emplacement.site_id', $sites)->whereIn('version_kit.type_kit_id', $types)
            ->groupBy('emplacement.site_id', 'version_kit.type_kit_id')
            ->get(['emplacement.site_id', 'version_kit.type_kit_id', DB::raw('count(*)::int as quantite')]);
        foreach ($kits as $k) {
            DB::table('inventaire_ligne')->insert([
                'inventaire_id' => $id, 'site_id' => $k->site_id, 'type_kit_id' => $k->type_kit_id,
                'theorique' => $k->quantite, 'cout_unitaire' => self::coutKit($k->type_kit_id),
            ]);
        }

        DB::table('inventaire')->where('id', $id)->update(['statut' => 'comptage', 'tour' => 1, 'lance_le' => now(), 'updated_at' => now()]);
    }

    /**
     * Enregistre un comptage par scan (n° de série, UID de lot, n° de kit) ou par saisie (article ou type de kit + quantité).
     *
     * @return array{libelle: string, quantite: int, total: int}
     */
    public static function compter(int $id, int $siteId, int $userId, ?string $code, ?int $articleId, ?int $typeKitId, ?int $quantite, ?int $emplacementId): array
    {
        $inv = self::campagne($id, true);
        if ($inv->statut !== 'comptage') {
            throw ValidationException::withMessages(['code' => 'Aucun comptage en cours pour cette campagne.']);
        }
        if (! DB::table('inventaire_site')->where(['inventaire_id' => $id, 'site_id' => $siteId])->exists()) {
            throw ValidationException::withMessages(['code' => 'Ce site ne fait pas partie de la campagne.']);
        }

        $uniteId = $kitId = null;
        $codeLu = null;
        if ($code !== null && trim($code) !== '') {
            $code = trim($code);
            $kit = DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
                ->where(fn ($q) => $q->where('kit_assemble.uid', strtoupper($code))->orWhere('kit_assemble.numero_serie', $code))
                ->where('kit_assemble.statut', 'assemble')->first(['kit_assemble.id', 'version_kit.type_kit_id']);
            if ($kit) {
                [$kitId, $typeKitId, $quantite] = [$kit->id, $kit->type_kit_id, 1];
            } else {
                $unites = array_values(array_filter(Stock::trouverUnites($code), fn ($u) => in_array($u->statut, ['en_stock', 'perdu'], true)));
                if (count($unites) === 1) {
                    $u = $unites[0];
                    $uniteId = $u->id;
                    $articleId = $u->article_id;
                    $quantite = $u->nature === 'serie' ? 1 : max(1, (int) ($quantite ?? 0));
                } elseif (count($unites) > 1) {
                    throw ValidationException::withMessages(['code' => 'Plusieurs unités portent ce n° : saisissez l’article et la quantité.']);
                } else {
                    // N° inconnu : noté pour régularisation, rattaché à l'article s'il est indiqué.
                    $codeLu = $code;
                    $quantite = 1;
                }
            }
        } elseif ($articleId === null && $typeKitId === null) {
            throw ValidationException::withMessages(['code' => 'Scannez un code ou choisissez un article ou un kit.']);
        }
        $quantite = max(1, (int) ($quantite ?? 1));

        // Un article sérialisé ou un kit n'est compté qu'une fois par tour.
        $dejaCompte = DB::table('inventaire_comptage')->where(['inventaire_id' => $id, 'tour' => $inv->tour]);
        $deja = match (true) {
            $kitId !== null => $dejaCompte->where('kit_id', $kitId)->exists(),
            $uniteId !== null && ! self::estLot($uniteId) => $dejaCompte->where('unite_id', $uniteId)->exists(),
            $codeLu !== null => $dejaCompte->where('code_lu', $codeLu)->exists(),
            default => false, // un lot ou une saisie peut être compté en plusieurs fois
        };
        if ($deja) {
            throw ValidationException::withMessages(['code' => 'Déjà compté dans ce tour.']);
        }

        $ligne = null;
        if ($articleId || $typeKitId) {
            $dansPerimetre = $articleId ? self::articles($inv)->contains($articleId) : self::typesKit($inv)->contains($typeKitId);
            if (! $dansPerimetre) {
                throw ValidationException::withMessages(['code' => 'Cet élément est hors du périmètre de la campagne.']);
            }
            $ligne = DB::table('inventaire_ligne')->where(['inventaire_id' => $id, 'site_id' => $siteId])
                ->when($articleId, fn ($q) => $q->where('article_id', $articleId), fn ($q) => $q->where('type_kit_id', $typeKitId))->first();
            if ($inv->tour === 2 && (! $ligne || ! $ligne->recompter)) {
                throw ValidationException::withMessages(['code' => 'Cet élément n’est pas concerné par le 2e comptage.']);
            }
            if (! $ligne) {
                // Trouvé alors que le théorique est nul : nouvelle ligne.
                $ligneId = DB::table('inventaire_ligne')->insertGetId([
                    'inventaire_id' => $id, 'site_id' => $siteId, 'article_id' => $articleId, 'type_kit_id' => $typeKitId, 'theorique' => 0,
                    'cout_unitaire' => $articleId ? DB::table('article')->where('id', $articleId)->value('cout_unitaire') : self::coutKit($typeKitId),
                ]);
                $ligne = DB::table('inventaire_ligne')->where('id', $ligneId)->first();
            }
        }

        DB::table('inventaire_comptage')->insert([
            'inventaire_id' => $id, 'ligne_id' => $ligne?->id, 'tour' => $inv->tour, 'site_id' => $siteId, 'emplacement_id' => $emplacementId,
            'unite_id' => $uniteId, 'kit_id' => $kitId, 'code_lu' => $codeLu, 'quantite' => $quantite, 'utilisateur_id' => $userId,
        ]);

        $total = $ligne ? (int) DB::table('inventaire_comptage')->where(['ligne_id' => $ligne->id, 'tour' => $inv->tour])->sum('quantite') : 0;
        $libelle = $articleId
            ? DB::table('article')->where('id', $articleId)->value('reference')
            : ($typeKitId ? 'Kit '.DB::table('type_kit')->where('id', $typeKitId)->value('reference') : "N° inconnu {$codeLu}");

        return ['libelle' => $libelle, 'quantite' => $quantite, 'total' => $total];
    }

    private static function estLot(int $uniteId): bool
    {
        return DB::table('unite_article')->where('id', $uniteId)->value('nature') === 'lot';
    }

    /** Fin d'un tour : les quantités comptées sont reportées sur les lignes (0 si rien n'a été compté). */
    public static function terminerTour(int $id): void
    {
        $inv = self::campagne($id, true);
        if ($inv->statut !== 'comptage') {
            throw ValidationException::withMessages(['statut' => 'Aucun comptage en cours.']);
        }
        $colonne = $inv->tour === 1 ? 'comptage1' : 'comptage2';
        $sommes = DB::table('inventaire_comptage')->where(['inventaire_id' => $id, 'tour' => $inv->tour])->whereNotNull('ligne_id')
            ->groupBy('ligne_id')->selectRaw('ligne_id, sum(quantite) as total')->pluck('total', 'ligne_id');
        foreach (DB::table('inventaire_ligne')->where('inventaire_id', $id)->when($inv->tour === 2, fn ($q) => $q->where('recompter', true))->get() as $l) {
            $compte = (int) ($sommes[$l->id] ?? 0);
            DB::table('inventaire_ligne')->where('id', $l->id)->update([$colonne => $compte, 'ecart' => $compte - $l->theorique]);
        }

        if ($inv->tour === 1) {
            DB::table('inventaire')->where('id', $id)->update(['statut' => 'analyse', 'updated_at' => now()]);
        } else {
            self::ouvrirDecompte($id);
        }
    }

    /** RG36 : le contrôleur désigne les lignes à recompter (par défaut, toutes celles en écart). */
    public static function lancerSecondComptage(int $id, array $lignes): void
    {
        $inv = self::campagne($id, true);
        if ($inv->statut !== 'analyse' || $inv->tour !== 1) {
            throw ValidationException::withMessages(['statut' => 'Le 2e comptage se lance après le 1er, une seule fois.']);
        }
        if (! $lignes) {
            throw ValidationException::withMessages(['lignes' => 'Choisissez au moins une ligne à recompter.']);
        }
        DB::table('inventaire_ligne')->where('inventaire_id', $id)->whereIn('id', $lignes)->update(['recompter' => true]);
        DB::table('inventaire')->where('id', $id)->update(['statut' => 'comptage', 'tour' => 2, 'updated_at' => now()]);
    }

    /** Passage au décompte final : la quantité retenue par défaut est le dernier comptage. */
    public static function ouvrirDecompte(int $id): void
    {
        $inv = self::campagne($id, true);
        if (! in_array($inv->statut, ['analyse', 'comptage'], true)) {
            throw ValidationException::withMessages(['statut' => 'Le décompte final suit les comptages.']);
        }
        DB::statement('UPDATE inventaire_ligne SET final = CASE WHEN recompter THEN coalesce(comptage2, 0) ELSE coalesce(comptage1, 0) END WHERE inventaire_id = ?', [$id]);
        self::valoriser($id);
        DB::table('inventaire')->where('id', $id)->update(['statut' => 'decompte', 'tour' => 3, 'updated_at' => now()]);
    }

    /** Le contrôleur corrige une quantité finale, avec justification si elle diffère du dernier comptage. */
    public static function fixerFinal(int $ligneId, int $final, ?string $justification): void
    {
        $l = DB::table('inventaire_ligne')->where('id', $ligneId)->first() ?? abort(404);
        $inv = self::campagne($l->inventaire_id, true);
        if ($inv->statut !== 'decompte') {
            throw ValidationException::withMessages(['final' => 'Le décompte final n’est pas ouvert.']);
        }
        $dernier = $l->recompter ? (int) $l->comptage2 : (int) $l->comptage1;
        if ($final !== $dernier && blank($justification)) {
            throw ValidationException::withMessages(['justification' => 'Justifiez une quantité finale différente du dernier comptage.']);
        }
        DB::table('inventaire_ligne')->where('id', $ligneId)->update(['final' => $final, 'justification' => $justification]);
        self::valoriser($l->inventaire_id);
    }

    /** RG37 : écart = final − théorique, valorisé au coût unitaire figé au lancement. */
    public static function valoriser(int $id): void
    {
        DB::statement('UPDATE inventaire_ligne SET ecart = final - theorique, valeur_ecart = (final - theorique) * cout_unitaire WHERE inventaire_id = ? AND final IS NOT NULL', [$id]);
        DB::table('inventaire')->where('id', $id)->update([
            'valeur_ecart' => DB::table('inventaire_ligne')->where('inventaire_id', $id)->sum('valeur_ecart'),
        ]);
    }

    /**
     * RG38 : clôture ; si demandé, les écarts sont appliqués au stock.
     *
     * @return array<string, int>
     */
    public static function cloturer(int $id, int $userId, bool $ajuster): array
    {
        $inv = self::campagne($id, true);
        if ($inv->statut !== 'decompte') {
            throw ValidationException::withMessages(['statut' => 'La clôture suit le décompte final.']);
        }
        self::valoriser($id);
        $bilan = ['lots_ajustes' => 0, 'series_perdues' => 0, 'series_retrouvees' => 0, 'lots_crees' => 0];
        $motif = "Inventaire n° {$id} : {$inv->libelle}";

        if ($ajuster) {
            foreach (DB::table('inventaire_ligne')->where('inventaire_id', $id)->whereNotNull('article_id')->where('ecart', '<>', 0)->get() as $l) {
                $serialise = (bool) DB::table('article')->where('id', $l->article_id)->value('est_serialise');
                $tour = $l->recompter ? 2 : 1;
                if ($serialise) {
                    $comptes = DB::table('inventaire_comptage')->where(['ligne_id' => $l->id, 'tour' => $tour])->whereNotNull('unite_id')->pluck('unite_id');
                    // Théoriques non retrouvés : perdus.
                    $absents = DB::table('unite_article')->join('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
                        ->where(['unite_article.article_id' => $l->article_id, 'unite_article.statut' => 'en_stock', 'emplacement.site_id' => $l->site_id])
                        ->whereNotIn('unite_article.id', $comptes)->pluck('unite_article.id');
                    foreach ($absents as $uniteId) {
                        Stock::mouvementUnite(Stock::verrouillerUnite($uniteId), 'ajustement', -1, $userId, $motif, ['statut' => 'perdu']);
                        DB::table('unite_article')->where('id', $uniteId)->update(['emplacement_id' => null]);
                        $bilan['series_perdues']++;
                    }
                    // Unités « perdues » retrouvées pendant l'inventaire : de nouveau en stock.
                    foreach (DB::table('unite_article')->whereIn('id', $comptes)->where('statut', 'perdu')->pluck('id') as $uniteId) {
                        $emplacement = DB::table('inventaire_comptage')->where(['ligne_id' => $l->id, 'unite_id' => $uniteId])->value('emplacement_id')
                            ?? self::emplacementParDefaut($l->site_id, DB::table('unite_article')->where('id', $uniteId)->value('proprietaire'));
                        Stock::mouvementUnite(Stock::verrouillerUnite($uniteId), 'ajustement', 1, $userId, $motif, ['statut' => 'en_stock']);
                        DB::table('unite_article')->where('id', $uniteId)->update(['emplacement_id' => $emplacement]);
                        $bilan['series_retrouvees']++;
                    }

                    continue;
                }

                $delta = (int) $l->ecart;
                $lots = DB::table('unite_article')->join('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
                    ->where(['unite_article.article_id' => $l->article_id, 'unite_article.statut' => 'en_stock', 'emplacement.site_id' => $l->site_id])
                    ->orderBy('unite_article.id')->lockForUpdate()->get(['unite_article.*']);
                if ($delta < 0) {
                    foreach ($lots as $lot) {
                        if ($delta === 0) {
                            break;
                        }
                        $retrait = min(-$delta, (int) $lot->quantite_restante);
                        if ($retrait > 0) {
                            Stock::mouvementUnite($lot, 'ajustement', -$retrait, $userId, $motif);
                            $delta += $retrait;
                            $bilan['lots_ajustes']++;
                        }
                    }
                } else {
                    // Surplus : nouveau lot de régularisation, à étiqueter.
                    $proprietaire = $lots->first()->proprietaire ?? 'GREENO';
                    $emplacement = $lots->first()->emplacement_id ?? self::emplacementParDefaut($l->site_id, $proprietaire);
                    $nouveau = DB::table('unite_article')->insertGetId([
                        'uid' => Uid::generer(Uid::LOT), 'nature' => 'lot', 'quantite_initiale' => $delta, 'quantite_restante' => 0, 'statut' => 'en_stock',
                        'article_id' => $l->article_id, 'proprietaire' => $proprietaire, 'emplacement_id' => $emplacement, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    Stock::mouvementUnite(Stock::verrouillerUnite($nouveau), 'ajustement', $delta, $userId, $motif);
                    $bilan['lots_crees']++;
                }
            }
        }

        DB::table('inventaire')->where('id', $id)->update([
            'statut' => 'cloture', 'stock_ajuste' => $ajuster, 'cloture_par' => $userId, 'cloture_le' => now(), 'updated_at' => now(),
        ]);

        return $bilan;
    }

    /** Emplacement de rangement par défaut d'un site : l'entrepôt de l'espace du propriétaire, ou le premier hors shop. */
    private static function emplacementParDefaut(int $siteId, ?string $proprietaire): ?int
    {
        return DB::table('emplacement')->where(['site_id' => $siteId, 'actif' => true, 'vente' => false])
            ->where(fn ($q) => $q->whereNull('espace')->orWhere('espace', $proprietaire))
            ->orderByRaw("type = 'entrepot' desc")->orderByRaw("type = 'retour' desc")->orderBy('id')->value('id');
    }
}
