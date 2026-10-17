<?php

namespace App\Support\Import;

use App\Models\TypeKit;
use App\Models\VersionKit;
use App\Support\Audit;
use App\Support\Nomenclature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * Import contrôlé du référentiel depuis Excel (EF01).
 *
 * Format attendu (export du CRM) :
 *  - feuille « products_parts »      : Catégorie | Nom | Type | Fabricant | Référence | Produits associés
 *  - feuille « products_serialized » : Catégorie | Nom | Fabricant | Verrouillable | Référence | Parts
 * « Produits associés » liste les kits (par nom) dont l'article fait partie : c'est la nomenclature, quantité 1.
 *
 * L'import est rejouable : chaque article et kit est retrouvé par sa référence. Les nomenclatures arrivent
 * en brouillon sans porteur de série ; une version verrouillée n'est jamais modifiée (RG10) : si la liste
 * d'articles change, une nouvelle version brouillon est créée.
 */
class ImportReferentiel
{
    private const MAX_LIGNES = 5000;

    /** @return array{articles: list<array<string, mixed>>, kits: list<array<string, mixed>>, erreurs: list<string>} */
    public function lire(string $chemin): array
    {
        $erreurs = [];
        try {
            $lecteur = new Xlsx;
            $lecteur->setReadDataOnly(true);
            $classeur = $lecteur->load($chemin);
        } catch (Throwable) {
            return ['articles' => [], 'kits' => [], 'erreurs' => ['Fichier illisible : il doit s’agir d’un classeur Excel .xlsx.']];
        }

        $feuilles = [];
        foreach ($classeur->getWorksheetIterator() as $feuille) {
            $feuilles[Str::lower(trim($feuille->getTitle()))] = $feuille->toArray(null, true, false, false);
        }

        $articles = $this->lignes($feuilles['products_parts'] ?? null, ['nom', 'type', 'fabricant', 'reference'], 'products_parts', $erreurs);
        $kits = $this->lignes($feuilles['products_serialized'] ?? null, ['nom', 'reference'], 'products_serialized', $erreurs);

        $vus = [];
        foreach ($articles as $i => &$a) {
            $ligne = $a['_ligne'];
            $a['est_serialise'] = $this->serialise($a['type'] ?? '');
            if ($a['est_serialise'] === null) {
                $erreurs[] = "products_parts, ligne {$ligne} : type « {$a['type']} » inconnu (attendu Sérialisé ou Non sérialisé).";
            }
            if ($a['est_serialise'] && $a['fabricant'] === '') {
                $erreurs[] = "products_parts, ligne {$ligne} : un article sérialisé doit avoir un fabricant (RG07).";
            }
            $a['kits'] = array_values(array_unique(array_filter(array_map('trim', explode(',', $a['produits associes'] ?? '')))));
            $this->controlerReference($a, 'products_parts', $vus, $erreurs);
        }
        unset($a);

        $vusKits = [];
        $noms = [];
        foreach ($kits as &$k) {
            $this->controlerReference($k, 'products_serialized', $vusKits, $erreurs);
            if (isset($noms[Str::lower($k['nom'])])) {
                $erreurs[] = "products_serialized, ligne {$k['_ligne']} : le nom de kit « {$k['nom']} » apparaît deux fois.";
            }
            $noms[Str::lower($k['nom'])] = true;
        }
        unset($k);

        foreach ($articles as $a) {
            foreach ($a['kits'] as $nomKit) {
                if (! isset($noms[Str::lower($nomKit)])) {
                    $erreurs[] = "products_parts, ligne {$a['_ligne']} : le kit « {$nomKit} » n’existe pas dans la feuille products_serialized.";
                }
            }
        }

        return ['articles' => $articles, 'kits' => $kits, 'erreurs' => array_values(array_unique($erreurs))];
    }

    /**
     * Ce que l'import va changer, sans rien écrire.
     *
     * @return array<string, mixed>
     */
    public function analyser(array $donnees): array
    {
        $existantsArticles = DB::table('article')->leftJoin('fabricant', 'fabricant.id', '=', 'article.fabricant_id')
            ->leftJoin('categorie', 'categorie.id', '=', 'article.categorie_id')
            ->get(['article.id', 'article.reference', 'article.nom', 'article.est_serialise', 'fabricant.nom as fabricant', 'categorie.libelle as categorie'])
            ->keyBy(fn ($a) => Str::upper($a->reference));
        $existantsKits = DB::table('type_kit')->get(['id', 'reference', 'nom'])->keyBy(fn ($k) => Str::upper($k->reference));
        $porteurs = DB::table('version_kit')->whereNotNull('article_porteur_id')->pluck('article_porteur_id')->flip();

        $articles = ['nouveaux' => [], 'modifies' => [], 'inchanges' => 0];
        $avertissements = [];
        foreach ($donnees['articles'] as $a) {
            $existant = $existantsArticles[Str::upper($a['reference'])] ?? null;
            if (! $existant) {
                $articles['nouveaux'][] = "{$a['reference']} · {$a['nom']}";

                continue;
            }
            $changes = [];
            if ($existant->nom !== $a['nom']) {
                $changes[] = 'nom';
            }
            if ((bool) $existant->est_serialise !== (bool) $a['est_serialise']) {
                $changes[] = $a['est_serialise'] ? 'devient sérialisé' : 'devient non sérialisé';
                if (! $a['est_serialise'] && isset($porteurs[$existant->id])) {
                    $avertissements[] = "{$a['reference']} est porteur de série d’un kit : il restera sérialisé (RG05).";
                }
            }
            if (($existant->fabricant ?? '') !== $a['fabricant']) {
                $changes[] = 'fabricant';
            }
            if (($existant->categorie ?? '') !== $a['categorie']) {
                $changes[] = 'catégorie';
            }
            if ($changes) {
                $articles['modifies'][] = "{$a['reference']} : ".implode(', ', $changes);
            } else {
                $articles['inchanges']++;
            }
        }

        $composition = $this->compositionParKit($donnees);
        $kits = ['nouveaux' => [], 'modifies' => [], 'inchanges' => 0];
        $nomenclatures = ['nouvelles' => 0, 'brouillons_mis_a_jour' => 0, 'nouvelles_versions' => 0, 'inchangees' => 0];
        foreach ($donnees['kits'] as $k) {
            $existant = $existantsKits[Str::upper($k['reference'])] ?? null;
            if (! $existant) {
                $kits['nouveaux'][] = "{$k['reference']} · {$k['nom']}";
            } elseif ($existant->nom !== $k['nom']) {
                $kits['modifies'][] = "{$k['reference']} : nom";
            } else {
                $kits['inchanges']++;
            }

            $refs = $composition[Str::lower($k['nom'])] ?? [];
            if (! $refs) {
                $avertissements[] = "Kit {$k['reference']} ({$k['nom']}) : aucun article, pas de nomenclature créée.";

                continue;
            }
            $serialises = array_filter($donnees['articles'], fn ($a) => $a['est_serialise'] && in_array($a['reference'], $refs, true));
            if (! $serialises) {
                $avertissements[] = "Kit {$k['reference']} ({$k['nom']}) : aucun article sérialisé, il ne pourra pas être verrouillé sans en ajouter un.";
            }
            if ($k['parts'] !== null && (int) $k['parts'] !== count($refs)) {
                $avertissements[] = "Kit {$k['reference']} : la colonne Parts indique {$k['parts']} articles, le fichier en rattache ".count($refs).'.';
            }

            $derniere = $existant ? VersionKit::where('type_kit_id', $existant->id)->orderByDesc('numero')->first() : null;
            $nomenclatures[$this->actionNomenclature($derniere, $refs)]++;
        }

        return [
            'articles' => $articles,
            'kits' => $kits,
            'nomenclatures' => $nomenclatures,
            'avertissements' => $avertissements,
            'fabricants_nouveaux' => array_values(array_diff(
                array_unique(array_filter([...array_column($donnees['articles'], 'fabricant'), ...array_column($donnees['kits'], 'fabricant')])),
                DB::table('fabricant')->pluck('nom')->all(),
            )),
        ];
    }

    /** @return array<string, int> */
    public function appliquer(array $donnees, int $utilisateurId, string $nomFichier): array
    {
        return DB::transaction(function () use ($donnees, $utilisateurId, $nomFichier) {
            $bilan = ['articles_crees' => 0, 'articles_modifies' => 0, 'kits_crees' => 0, 'kits_modifies' => 0,
                'nomenclatures_creees' => 0, 'brouillons_mis_a_jour' => 0, 'nouvelles_versions' => 0, 'serialisation_conservee' => 0];

            $fabricant = $this->resolveur('fabricant', 'nom');
            $categorie = $this->resolveur('categorie', 'libelle');
            $porteurs = DB::table('version_kit')->whereNotNull('article_porteur_id')->pluck('article_porteur_id')->flip();

            $idsArticles = [];
            foreach ($donnees['articles'] as $a) {
                $valeurs = [
                    'nom' => $a['nom'],
                    'est_serialise' => (bool) $a['est_serialise'],
                    'fabricant_id' => $fabricant($a['fabricant']),
                    'categorie_id' => $categorie($a['categorie']),
                ] + ($a['cout'] !== null ? ['cout_unitaire' => $a['cout']] : []);
                $existant = DB::table('article')->whereRaw('upper(reference) = ?', [Str::upper($a['reference'])])->first();
                if (! $existant) {
                    $idsArticles[$a['reference']] = DB::table('article')->insertGetId($valeurs + ['reference' => $a['reference'], 'created_at' => now(), 'updated_at' => now()]);
                    $bilan['articles_crees']++;

                    continue;
                }
                if (! $valeurs['est_serialise'] && isset($porteurs[$existant->id])) {
                    $valeurs['est_serialise'] = true; // RG05
                    $bilan['serialisation_conservee']++;
                }
                $avant = ['nom' => $existant->nom, 'est_serialise' => (bool) $existant->est_serialise, 'fabricant_id' => $existant->fabricant_id, 'categorie_id' => $existant->categorie_id]
                    + ($a['cout'] !== null ? ['cout_unitaire' => $existant->cout_unitaire === null ? null : (float) $existant->cout_unitaire] : []);
                if ($avant != $valeurs) {
                    DB::table('article')->where('id', $existant->id)->update($valeurs + ['updated_at' => now()]);
                    $bilan['articles_modifies']++;
                }
                $idsArticles[$a['reference']] = $existant->id;
            }

            $composition = $this->compositionParKit($donnees);
            foreach ($donnees['kits'] as $k) {
                $valeurs = ['nom' => $k['nom'], 'fabricant_id' => $fabricant($k['fabricant']), 'categorie_id' => $categorie($k['categorie'])];
                $type = TypeKit::whereRaw('upper(reference) = ?', [Str::upper($k['reference'])])->first();
                if (! $type) {
                    // Le fichier ne fournit pas de PartNo : la référence en tient lieu jusqu'à correction.
                    $type = TypeKit::create($valeurs + ['reference' => $k['reference'], 'partno' => $k['reference']]);
                    $bilan['kits_crees']++;
                } else {
                    $type->fill($valeurs);
                    if ($type->isDirty()) {
                        $type->save();
                        $bilan['kits_modifies']++;
                    }
                }

                $refs = $composition[Str::lower($k['nom'])] ?? [];
                if (! $refs) {
                    continue;
                }
                $ids = array_map(fn ($r) => $idsArticles[$r], $refs);
                $derniere = $type->versions()->first();
                $action = $this->actionNomenclature($derniere, $refs);

                if ($action === 'nouvelles') {
                    $version = $type->versions()->create(['numero' => 1, 'article_porteur_id' => null]);
                    Nomenclature::enregistrer($version, null, array_map(fn ($id) => ['article_id' => $id, 'quantite' => 1], $ids));
                    $bilan['nomenclatures_creees']++;
                } elseif ($action === 'brouillons_mis_a_jour' || $action === 'nouvelles_versions') {
                    $version = $action === 'nouvelles_versions'
                        ? $type->versions()->create(['numero' => $derniere->numero + 1, 'article_porteur_id' => null])
                        : $derniere;
                    // Les quantités déjà saisies sont conservées ; le porteur aussi s'il reste dans la liste.
                    $quantites = DB::table('composition')->where('version_kit_id', $derniere->id)->pluck('quantite', 'article_id');
                    $porteur = in_array($derniere->article_porteur_id, $ids, true) ? $derniere->article_porteur_id : null;
                    Nomenclature::enregistrer($version, $porteur, array_map(fn ($id) => ['article_id' => $id, 'quantite' => (int) ($quantites[$id] ?? 1)], $ids));
                    $bilan[$action]++;
                }
            }

            Audit::enregistrer('importer', 'referentiel', apres: $bilan + ['fichier' => $nomFichier], utilisateurId: $utilisateurId);

            return $bilan;
        });
    }

    /** @param list<string> $refs */
    private function actionNomenclature(?VersionKit $derniere, array $refs): string
    {
        if (! $derniere) {
            return 'nouvelles';
        }
        $actuels = DB::table('composition')->join('article', 'article.id', '=', 'composition.article_id')
            ->where('version_kit_id', $derniere->id)->pluck('article.reference')->map(fn ($r) => Str::upper($r))->sort()->values()->all();
        $voulus = collect($refs)->map(fn ($r) => Str::upper($r))->sort()->values()->all();
        if ($actuels === $voulus) {
            return 'inchangees';
        }

        return $derniere->verrouillee ? 'nouvelles_versions' : 'brouillons_mis_a_jour';
    }

    /** @return array<string, list<string>> nom de kit en minuscules => références d'articles */
    private function compositionParKit(array $donnees): array
    {
        $composition = [];
        foreach ($donnees['articles'] as $a) {
            foreach ($a['kits'] as $nomKit) {
                $composition[Str::lower($nomKit)][] = $a['reference'];
            }
        }

        return array_map(fn ($refs) => array_values(array_unique($refs)), $composition);
    }

    /** Retrouve ou crée une ligne de table simple (fabricant, catégorie) par son libellé. */
    private function resolveur(string $table, string $colonne): \Closure
    {
        $cache = DB::table($table)->pluck('id', $colonne)->mapWithKeys(fn ($id, $nom) => [Str::lower($nom) => $id])->all();

        return function (string $valeur) use (&$cache, $table, $colonne) {
            if ($valeur === '') {
                return null;
            }

            return $cache[Str::lower($valeur)] ??= DB::table($table)->insertGetId([$colonne => $valeur, 'created_at' => now(), 'updated_at' => now()]);
        };
    }

    /** @return list<array<string, mixed>> */
    private function lignes(?array $feuille, array $obligatoires, string $nom, array &$erreurs): array
    {
        if ($feuille === null) {
            $erreurs[] = "Feuille « {$nom} » absente du classeur.";

            return [];
        }
        $entetes = array_map(fn ($e) => Str::lower(Str::ascii(trim((string) $e))), array_shift($feuille) ?? []);
        foreach ($obligatoires as $colonne) {
            if (! in_array($colonne, $entetes, true)) {
                $erreurs[] = "Feuille « {$nom} » : colonne « {$colonne} » manquante.";
            }
        }
        if (count($feuille) > self::MAX_LIGNES) {
            $erreurs[] = "Feuille « {$nom} » : plus de ".self::MAX_LIGNES.' lignes.';

            return [];
        }

        $resultat = [];
        foreach ($feuille as $i => $ligne) {
            $valeurs = [];
            foreach ($entetes as $j => $entete) {
                if ($entete !== '') {
                    $valeur = $ligne[$j] ?? null;
                    $valeurs[$entete] = is_float($valeur) && floor($valeur) === $valeur ? (string) (int) $valeur : trim((string) $valeur);
                }
            }
            if (implode('', $valeurs) === '') {
                continue;
            }
            $resultat[] = [
                '_ligne' => $i + 2,
                'nom' => Str::limit($valeurs['nom'] ?? '', 255, ''),
                'reference' => $valeurs['reference'] ?? '',
                'type' => $valeurs['type'] ?? '',
                'fabricant' => $valeurs['fabricant'] ?? '',
                'categorie' => $valeurs['categorie'] ?? '',
                'produits associes' => $valeurs['produits associes'] ?? '',
                'parts' => isset($valeurs['parts']) && $valeurs['parts'] !== '' ? (int) $valeurs['parts'] : null,
                // Colonne facultative « Coût unitaire » (FCFA), pour la valorisation des inventaires.
                'cout' => isset($valeurs['cout unitaire']) && is_numeric(str_replace([' ', ','], ['', '.'], $valeurs['cout unitaire'])) ? (float) str_replace([' ', ','], ['', '.'], $valeurs['cout unitaire']) : null,
            ];
        }

        return $resultat;
    }

    private function controlerReference(array $ligne, string $feuille, array &$vus, array &$erreurs): void
    {
        $reference = $ligne['reference'];
        if ($reference === '' || $ligne['nom'] === '') {
            $erreurs[] = "{$feuille}, ligne {$ligne['_ligne']} : référence et nom obligatoires.";

            return;
        }
        if (mb_strlen($reference) > 64) {
            $erreurs[] = "{$feuille}, ligne {$ligne['_ligne']} : référence de plus de 64 caractères.";
        }
        $cle = Str::upper($reference);
        if (isset($vus[$cle])) {
            $erreurs[] = "{$feuille}, ligne {$ligne['_ligne']} : la référence {$reference} apparaît deux fois.";
        }
        $vus[$cle] = true;
    }

    private function serialise(string $type): ?bool
    {
        $t = Str::lower(Str::ascii(trim($type)));

        return match (true) {
            in_array($t, ['serialise', 'serialisé', 'oui', 'serialized'], true) => true,
            in_array($t, ['non serialise', 'non', 'not serialized', 'non-serialise'], true) => false,
            default => null,
        };
    }
}
