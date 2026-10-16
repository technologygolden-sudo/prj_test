<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\TypeKit;
use App\Support\Audit;
use App\Support\Emplacements;
use App\Support\Perimetre;
use App\Support\Stock;
use App\Support\Uid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assemblage guidé par scan (EF05, EF06, RG06, RG09, RG11, processus 4.2) :
 * chaque scan est contrôlé aussitôt, puis la validation reverrouille et revérifie tout dans une transaction.
 */
class AssemblageController extends Controller
{
    public function create(Request $request): Response
    {
        $versionId = $request->integer('version') ?: null;

        // Tous les types de kit sont listés ; seuls ceux qui ont une version verrouillée (donc un porteur) s'assemblent.
        $types = TypeKit::with(['versionActive.porteur:id,reference', 'brouillon'])->withCount('versions')->orderBy('reference')->get()
            ->map(fn (TypeKit $t) => [
                'kit_id' => $t->id,
                'version_id' => $t->versionActive?->id,
                'libelle' => $t->versionActive
                    ? "{$t->reference} · {$t->nom} (v{$t->versionActive->numero}) — porteur : {$t->versionActive->porteur?->reference}"
                    : "{$t->reference} · {$t->nom}",
                'raison' => $t->versionActive ? null : match (true) {
                    $t->versions_count === 0 => 'Aucune nomenclature',
                    $t->brouillon?->article_porteur_id === null => 'Porteur de série à choisir, puis verrouiller',
                    default => 'Nomenclature à verrouiller',
                },
            ])
            ->sortBy(fn ($t) => $t['version_id'] ? 0 : 1)->values();

        return Inertia::render('Stock/Assemblage', [
            'types' => $types,
            'nomenclature' => $versionId ? $this->nomenclature($versionId) : null,
            'imprimantes' => ReceptionController::imprimantes(),
        ]);
    }

    /** Contrôle d'un scan pendant l'assemblage (JSON). */
    public function verifier(Request $request)
    {
        $donnees = $request->validate([
            'version_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:64'],
        ]);
        $nomenclature = $this->nomenclature($donnees['version_id']);
        $articles = array_column($nomenclature['lignes'], 'article_id');

        $unites = Stock::trouverUnites($donnees['code'], $articles);
        if (count($unites) > 1) {
            return response()->json(['message' => 'Plusieurs unités portent ce n° (fabricants différents) : vérifiez l’article.'], 422);
        }
        $u = $unites[0] ?? null;
        if (! $u) {
            return response()->json(['message' => Stock::aideScan($donnees['code'], 'Code inconnu.')], 422);
        }
        if (! in_array($u->article_id, $articles, true)) {
            return response()->json(['message' => 'Cet article ne fait pas partie de la nomenclature.'], 422);
        }
        if ($u->statut !== 'en_stock') {
            return response()->json(['message' => "Cette unité n'est pas en stock (statut : {$u->statut})."], 422);
        }
        if ($u->etat !== 'bon') {
            return response()->json(['message' => 'Cette unité est déclarée défectueuse : elle doit passer par le Lab.'], 422);
        }
        if (! Perimetre::couvre('unite_article', $u->id)) {
            return response()->json(['message' => 'Cette unité n’est pas dans le stock de votre site.'], 422);
        }

        $emplacement = EmplacementController::libelle($u->emplacement_id);

        return response()->json([
            'unite_id' => $u->id, 'uid' => $u->uid, 'article_id' => $u->article_id, 'nature' => $u->nature,
            'numero_serie' => $u->numero_serie, 'disponible' => $u->quantite_restante,
            'proprietaire' => $u->proprietaire, 'site_id' => $emplacement['site_id'] ?? null, 'emplacement' => $emplacement['libelle'] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'version_id' => ['required', 'integer'],
            // Les articles sérialisés sont scannés ; les non sérialisés peuvent l'être, sinon ils sont prélevés automatiquement.
            'composants' => ['required', 'array', 'min:1', 'max:1000'],
            'composants.*.unite_id' => ['required', 'integer', 'distinct'],
            'composants.*.quantite' => ['required', 'integer', 'min:1'],
        ]);

        $kitId = DB::transaction(function () use ($request, $donnees) {
            $userId = $request->user()->id;
            $version = DB::table('version_kit')->where('id', $donnees['version_id'])->first();
            $active = $version ? TypeKit::find($version->type_kit_id)?->versionActive : null;
            if (! $version || ! $active || $active->id !== $version->id) {
                throw ValidationException::withMessages(['version_id' => 'Ce kit n’a plus cette version active : rechargez la page.']);
            }
            $attendu = DB::table('composition')->where('version_kit_id', $version->id)->pluck('quantite', 'article_id')->map(fn ($q) => (int) $q)->all();

            // Verrouillage des unités puis contrôle complet (RG11).
            $unites = DB::table('unite_article')->whereIn('id', array_column($donnees['composants'], 'unite_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $recu = [];
            $porteur = null;
            foreach ($donnees['composants'] as $c) {
                $u = $unites[$c['unite_id']] ?? null;
                if (! $u || $u->statut !== 'en_stock' || $u->emplacement_id === null) {
                    throw ValidationException::withMessages(['composants' => 'Un composant n’est plus en stock : rescannez-le.']);
                }
                if ($u->etat !== 'bon') {
                    throw ValidationException::withMessages(['composants' => "L'unité {$u->uid} est défectueuse : elle doit passer par le Lab."]);
                }
                Perimetre::exiger('unite_article', $u->id);
                if (! isset($attendu[$u->article_id])) {
                    throw ValidationException::withMessages(['composants' => "L'unité {$u->uid} n'appartient pas à la nomenclature."]);
                }
                $quantite = $u->nature === 'serie' ? 1 : (int) $c['quantite'];
                if ($quantite > $u->quantite_restante) {
                    throw ValidationException::withMessages(['composants' => "Le lot {$u->uid} ne contient plus que {$u->quantite_restante}."]);
                }
                $recu[$u->article_id] = ($recu[$u->article_id] ?? 0) + $quantite;
                if ($u->article_id === $version->article_porteur_id) {
                    $porteur = $u;
                }
            }
            if (! $porteur) {
                throw ValidationException::withMessages(['composants' => 'Scannez d’abord l’article porteur de série : il donne son n° au kit.']);
            }
            $sitePorteur = DB::table('emplacement')->where('id', $porteur->emplacement_id)->value('site_id');

            // Quantités manquantes : les articles sérialisés doivent être scannés, les lots sont prélevés automatiquement.
            $articles = DB::table('article')->whereIn('id', array_keys($attendu))->get(['id', 'reference', 'est_serialise'])->keyBy('id');
            $composants = $donnees['composants'];
            foreach ($attendu as $articleId => $voulu) {
                $manque = $voulu - ($recu[$articleId] ?? 0);
                if ($manque < 0) {
                    throw ValidationException::withMessages(['composants' => "Trop d’unités scannées pour {$articles[$articleId]->reference} ({$recu[$articleId]} pour {$voulu})."]);
                }
                if ($manque === 0) {
                    continue;
                }
                if ($articles[$articleId]->est_serialise) {
                    throw ValidationException::withMessages(['composants' => "Scannez encore {$manque} unité(s) sérialisée(s) de {$articles[$articleId]->reference}."]);
                }
                $lots = DB::table('unite_article')->join('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
                    ->where(['unite_article.article_id' => $articleId, 'unite_article.nature' => 'lot', 'unite_article.statut' => 'en_stock',
                        'unite_article.etat' => 'bon', 'unite_article.proprietaire' => $porteur->proprietaire, 'emplacement.site_id' => $sitePorteur])
                    ->where('unite_article.quantite_restante', '>', 0)
                    ->whereNotIn('unite_article.id', $unites->keys())
                    ->orderBy('unite_article.id')->lockForUpdate()->get(['unite_article.*']);
                foreach ($lots as $lot) {
                    if ($manque === 0) {
                        break;
                    }
                    $pris = min($manque, (int) $lot->quantite_restante);
                    $unites[$lot->id] = $lot;
                    $composants[] = ['unite_id' => $lot->id, 'quantite' => $pris];
                    $manque -= $pris;
                }
                if ($manque > 0) {
                    throw ValidationException::withMessages(['composants' => "Stock insuffisant sur ce site pour {$articles[$articleId]->reference} : il manque {$manque}."]);
                }
            }
            $donnees['composants'] = $composants;

            // Stock séparé : un kit ne mélange pas GREENO et TEVIA, et ses composants sont sur le même site.
            $sites = DB::table('emplacement')->whereIn('id', $unites->pluck('emplacement_id'))->distinct()->pluck('site_id');
            if ($unites->pluck('proprietaire')->unique()->count() > 1) {
                throw ValidationException::withMessages(['composants' => 'Les composants appartiennent à des propriétaires différents (GREENO / TEVIA) : cédez-les d’abord.']);
            }
            if ($sites->count() > 1) {
                throw ValidationException::withMessages(['composants' => 'Les composants sont sur des sites différents : transférez-les d’abord.']);
            }

            $kitId = DB::table('kit_assemble')->insertGetId([
                'uid' => Uid::generer(Uid::KIT), 'numero_serie' => '', 'statut' => 'assemble', 'assemble_le' => now(),
                'version_kit_id' => $version->id, 'unite_porteur_id' => $porteur->id, 'utilisateur_id' => $userId,
                'proprietaire' => $porteur->proprietaire, 'emplacement_id' => $porteur->emplacement_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Emplacements::tracer('affectation', 'kit_id', $kitId, null, $porteur->emplacement_id, $userId, 'Assemblage');

            foreach ($donnees['composants'] as $c) {
                $u = $unites[$c['unite_id']];
                $quantite = $u->nature === 'serie' ? 1 : (int) $c['quantite'];
                DB::table('contenu_kit')->insert(['kit_id' => $kitId, 'unite_id' => $u->id, 'quantite' => $quantite]);
                // RG09 : un article sérialisé passe « Assemblé » ; un lot vidé passe « Épuisé ».
                Stock::mouvementUnite($u, 'consommation', -$quantite, $userId, "Assemblage du kit {$kitId}", $u->nature === 'serie' ? ['statut' => 'assemble'] : []);
                if ($u->nature === 'serie') {
                    DB::table('unite_article')->where('id', $u->id)->update(['emplacement_id' => null]); // rangé dans le kit
                }
            }

            Audit::enregistrer('assembler', 'kit_assemble', apres: ['id' => $kitId, 'version_kit_id' => $version->id, 'composants' => $donnees['composants']]);

            return $kitId;
        });

        return redirect()->route('kits-assembles.show', $kitId)->with('succes', 'Kit assemblé. Imprimez son étiquette.');
    }

    /** @return array{version_id: int, porteur_id: int, lignes: list<array<string, mixed>>} */
    private function nomenclature(int $versionId): array
    {
        $version = DB::table('version_kit')->where('id', $versionId)->where('verrouillee', true)->first() ?? abort(404);

        return [
            'version_id' => $version->id,
            'porteur_id' => $version->article_porteur_id,
            'lignes' => DB::table('composition')->join('article', 'article.id', '=', 'composition.article_id')
                ->where('version_kit_id', $version->id)->orderByRaw('article.id = ? desc', [$version->article_porteur_id])->orderBy('article.reference')
                ->get(['article.id as article_id', 'article.reference', 'article.nom', 'article.est_serialise', 'composition.quantite'])
                ->map(fn ($l) => (array) $l)->all(),
        ];
    }
}
