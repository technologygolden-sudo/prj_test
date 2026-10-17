<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Emplacements;
use App\Support\Perimetre;
use App\Support\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Stock par unité, fiche (EF07) et ajustements d'inventaire (RG27, processus 4.5). */
class UniteController extends Controller
{
    public const STATUTS = ['en_stock', 'assemble', 'epuise', 'expedie', 'rebute', 'retourne', 'perdu'];

    public function index(Request $request): Response
    {
        $filtres = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'statut' => ['nullable', 'in:'.implode(',', [...self::STATUTS, 'en_transit'])],
            'site' => ['nullable', 'integer'],
            'emplacement' => ['nullable', 'integer'],
            'proprietaire' => ['nullable', 'in:GREENO,TEVIA'],
            'etat' => ['nullable', 'in:bon,defectueux'],
        ]);

        $filtrer = fn ($q) => Perimetre::filtrer($q)
            ->when($filtres['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->when($filtres['site'] ?? null, fn ($q, $s) => $q->where('emplacement.site_id', $s))
            ->when($filtres['emplacement'] ?? null, fn ($q, $e) => $q->where('unite_article.emplacement_id', $e))
            ->when($filtres['proprietaire'] ?? null, fn ($q, $p) => $q->where('unite_article.proprietaire', $p))
            ->when($filtres['etat'] ?? null, fn ($q, $e) => $q->where('unite_article.etat', $e));

        $unites = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->leftJoin('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')->leftJoin('site', 'site.id', '=', 'emplacement.site_id')
            ->when($filtres['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('uid', strtoupper($t))->orWhere('numero_serie', 'ilike', "%{$t}%")
                ->orWhere('article.reference', 'ilike', "%{$t}%")->orWhere('article.nom', 'ilike', "%{$t}%")))
            ->tap($filtrer)
            ->select('unite_article.id', 'uid', 'nature', 'numero_serie', 'quantite_initiale', 'quantite_restante', 'statut', 'unite_article.etat',
                'unite_article.proprietaire', 'article.reference', 'article.nom', DB::raw("concat_ws(' › ', site.nom, emplacement.nom) as emplacement_libelle"))
            ->selectSub(fn ($q) => $q->from('impression_etiquette')->whereColumn('unite_id', 'unite_article.id')->selectRaw('count(*)'), 'impressions')
            ->orderByDesc('unite_article.id')
            ->paginate(30)->withQueryString();

        $synthese = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->leftJoin('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
            ->where('statut', 'en_stock')->tap($filtrer)
            ->groupBy('article.id', 'article.reference', 'article.nom')
            ->orderBy('article.reference')
            ->get(['article.reference', 'article.nom', DB::raw('sum(quantite_restante) as quantite')]);

        return Inertia::render('Stock/Unites/Index', [
            'unites' => $unites, 'filtres' => $filtres, 'synthese' => $synthese,
            'parSite' => $request->query('vue') === 'sites' ? $this->parSite($filtres) : null,
            'vue' => in_array($request->query('vue'), ['sites', 'articles'], true) ? $request->query('vue') : 'liste',
            'sites' => DB::table('site')->where('actif', true)->when(Perimetre::site(), fn ($q, $s) => $q->where('id', $s))->orderByRaw("type = 'entrepot' desc")->orderBy('nom')->get(['id', 'nom']),
            'emplacements' => Emplacements::liste($filtres['site'] ?? null),
            'imprimantes' => ReceptionController::imprimantes(),
        ]);
    }

    /**
     * Stock disponible par site puis par emplacement : quantités par article et kits assemblés par type.
     *
     * @return list<array<string, mixed>>
     */
    private function parSite(array $filtres): array
    {
        $articles = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->join('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
            ->where('unite_article.statut', 'en_stock')->tap(fn ($q) => Perimetre::filtrer($q))
            ->when($filtres['site'] ?? null, fn ($q, $s) => $q->where('emplacement.site_id', $s))
            ->when($filtres['proprietaire'] ?? null, fn ($q, $p) => $q->where('unite_article.proprietaire', $p))
            ->when($filtres['etat'] ?? null, fn ($q, $e) => $q->where('unite_article.etat', $e))
            ->groupBy('emplacement.id', 'article.reference', 'article.nom', 'unite_article.proprietaire')
            ->get(['emplacement.id as emplacement_id', 'article.reference', 'article.nom', 'unite_article.proprietaire',
                DB::raw('sum(quantite_restante) as quantite'), DB::raw("sum(case when unite_article.etat = 'defectueux' then quantite_restante else 0 end) as defectueux")])
            ->groupBy('emplacement_id');

        $kits = DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
            ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->join('emplacement', 'emplacement.id', '=', 'kit_assemble.emplacement_id')
            ->where('kit_assemble.statut', 'assemble')->tap(fn ($q) => Perimetre::filtrer($q))
            ->when($filtres['site'] ?? null, fn ($q, $s) => $q->where('emplacement.site_id', $s))
            ->when($filtres['proprietaire'] ?? null, fn ($q, $p) => $q->where('kit_assemble.proprietaire', $p))
            ->when($filtres['etat'] ?? null, fn ($q, $e) => $q->where('kit_assemble.etat', $e))
            ->groupBy('emplacement.id', 'type_kit.reference', 'type_kit.nom', 'kit_assemble.proprietaire')
            ->get(['emplacement.id as emplacement_id', 'type_kit.reference', 'type_kit.nom', 'kit_assemble.proprietaire',
                DB::raw('count(*) as quantite'), DB::raw("sum(case when kit_assemble.etat = 'defectueux' then 1 else 0 end) as defectueux")])
            ->groupBy('emplacement_id');

        return Emplacements::liste($filtres['site'] ?? null)->groupBy('site_id')->map(function ($emplacements) use ($articles, $kits) {
            $lignes = $emplacements->map(fn ($e) => $e + [
                'articles' => $articles->get($e['id'], collect())->values(),
                'kits' => $kits->get($e['id'], collect())->values(),
            ]);

            return [
                'site' => $emplacements->first()['site'],
                'site_id' => $emplacements->first()['site_id'],
                'total_articles' => $lignes->sum(fn ($e) => $e['articles']->sum('quantite')),
                'total_kits' => $lignes->sum(fn ($e) => $e['kits']->sum('quantite')),
                'emplacements' => $lignes->values(),
            ];
        })->values()->all();
    }

    public function show(int $unite): Response
    {
        abort_unless(Perimetre::couvre('unite_article', $unite), 403, 'Cette unité n’est pas dans le stock de votre site.');
        $u = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->leftJoin('fabricant', 'fabricant.id', '=', 'unite_article.fabricant_id')
            ->leftJoin('reception', 'reception.id', '=', 'unite_article.reception_id')
            ->where('unite_article.id', $unite)
            ->first(['unite_article.*', 'article.reference', 'article.nom', 'fabricant.nom as fabricant', 'reception.num_bl', 'reception.date as date_reception'])
            ?? abort(404);

        $kit = DB::table('contenu_kit')->join('kit_assemble', 'kit_assemble.id', '=', 'contenu_kit.kit_id')
            ->where('contenu_kit.unite_id', $unite)->where('kit_assemble.statut', '<>', 'desassemble')
            ->first(['kit_assemble.id', 'kit_assemble.uid', 'kit_assemble.numero_serie', 'kit_assemble.statut']);

        return Inertia::render('Stock/Unites/Fiche', [
            'unite' => $u,
            'kitParent' => $kit,
            'emplacement' => EmplacementController::libelle($u->emplacement_id),
            'emplacements' => Emplacements::liste(),
            'historique' => EmplacementController::historique('unite_id', $unite),
            'mouvements' => DB::table('mouvement_stock')->leftJoin('users', 'users.id', '=', 'mouvement_stock.utilisateur_id')
                ->where('unite_id', $unite)->orderByDesc('mouvement_stock.id')
                ->get(['mouvement_stock.*', 'users.name as utilisateur']),
            'impressions' => self::impressions('unite_id', $unite),
            'imprimantes' => ReceptionController::imprimantes(),
        ]);
    }

    public function ajuster(Request $request, int $unite): RedirectResponse
    {
        $donnees = $request->validate([
            'quantite' => ['required', 'integer', 'not_in:0', 'between:-100000,100000'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $unite, $donnees) {
            $u = Stock::verrouillerUnite($unite) ?? abort(404);
            $avant = (array) $u;

            if ($u->nature === 'serie') {
                // Une unité sérialisée est perdue (−1) ou retrouvée (+1).
                $perdue = $donnees['quantite'] === -1 && $u->statut === 'en_stock';
                $retrouvee = $donnees['quantite'] === 1 && $u->statut === 'perdu';
                if (! $perdue && ! $retrouvee) {
                    throw ValidationException::withMessages(['quantite' => 'Une unité sérialisée en stock peut seulement être déclarée perdue (−1), et une unité perdue retrouvée (+1).']);
                }
                $u = Stock::mouvementUnite($u, 'ajustement', $donnees['quantite'], $request->user()->id, $donnees['motif'], ['statut' => $perdue ? 'perdu' : 'en_stock']);
            } else {
                if (! in_array($u->statut, ['en_stock', 'epuise'], true)) {
                    throw ValidationException::withMessages(['quantite' => 'Seul un lot en stock ou épuisé peut être ajusté.']);
                }
                $u = Stock::mouvementUnite($u, 'ajustement', $donnees['quantite'], $request->user()->id, $donnees['motif']);
            }

            Audit::enregistrer('ajuster', 'unite_article', $avant, (array) $u + ['motif' => $donnees['motif']]);
        });

        return back()->with('succes', 'Ajustement enregistré.');
    }

    public static function impressions(string $colonne, int $id)
    {
        return DB::table('impression_etiquette')
            ->join('imprimante', 'imprimante.id', '=', 'impression_etiquette.imprimante_id')
            ->leftJoin('users', 'users.id', '=', 'impression_etiquette.utilisateur_id')
            ->where($colonne, $id)->orderByDesc('impression_etiquette.id')
            ->get(['impression_etiquette.horodatage', 'copies', 'est_reimpression', 'motif', 'imprimante.nom as imprimante', 'users.name as utilisateur']);
    }
}
