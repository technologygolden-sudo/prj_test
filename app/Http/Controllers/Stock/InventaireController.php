<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Inventaires;
use App\Support\Perimetre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Campagnes d'inventaire : paramétrage, comptages, décompte final, écarts valorisés et clôture. */
class InventaireController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Stock/Inventaires/Index', [
            'inventaires' => DB::table('inventaire')->leftJoin('users', 'users.id', '=', 'inventaire.cree_par')
                ->select('inventaire.*', 'users.name as createur')
                ->selectSub(fn ($q) => $q->from('inventaire_site')->join('site', 'site.id', '=', 'inventaire_site.site_id')
                    ->whereColumn('inventaire_id', 'inventaire.id')->selectRaw("string_agg(site.nom, ', ' order by site.nom)"), 'sites')
                ->orderByDesc('inventaire.id')->paginate(25),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Stock/Inventaires/Parametres', $this->listes() + ['inventaire' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->valider($request);
        $id = DB::transaction(function () use ($request, $d) {
            $id = DB::table('inventaire')->insertGetId([
                'libelle' => $d['libelle'], 'date_prevue' => $d['date_prevue'], 'tous_articles' => $d['tous_articles'], 'tous_kits' => $d['tous_kits'],
                'commentaire' => $d['commentaire'] ?? null, 'cree_par' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->enregistrerPerimetre($id, $d);
            Audit::enregistrer('creer', 'inventaire', apres: $d + ['id' => $id]);

            return $id;
        });

        return redirect()->route('inventaires.show', $id)->with('succes', 'Campagne créée en brouillon : vérifiez le périmètre puis lancez-la.');
    }

    public function edit(int $inventaire): Response
    {
        $inv = Inventaires::campagne($inventaire);
        abort_unless($inv->statut === 'brouillon', 403, 'Une campagne lancée ne se modifie plus.');
        $cibles = DB::table('inventaire_cible')->where('inventaire_id', $inventaire)->get()->groupBy('type');

        return Inertia::render('Stock/Inventaires/Parametres', $this->listes() + [
            'inventaire' => (array) $inv + [
                'sites' => DB::table('inventaire_site')->where('inventaire_id', $inventaire)->pluck('site_id'),
                'categories' => ($cibles['categorie'] ?? collect())->pluck('cible_id'),
                'articles' => ($cibles['article'] ?? collect())->pluck('cible_id'),
                'types_kit' => ($cibles['type_kit'] ?? collect())->pluck('cible_id'),
            ],
        ]);
    }

    public function update(Request $request, int $inventaire): RedirectResponse
    {
        $inv = Inventaires::campagne($inventaire);
        if ($inv->statut !== 'brouillon') {
            return back()->with('alerte', 'Une campagne lancée ne se modifie plus.');
        }
        $d = $this->valider($request);
        DB::transaction(function () use ($inventaire, $d) {
            DB::table('inventaire')->where('id', $inventaire)->update([
                'libelle' => $d['libelle'], 'date_prevue' => $d['date_prevue'], 'tous_articles' => $d['tous_articles'], 'tous_kits' => $d['tous_kits'],
                'commentaire' => $d['commentaire'] ?? null, 'updated_at' => now(),
            ]);
            $this->enregistrerPerimetre($inventaire, $d);
            Audit::enregistrer('modifier', 'inventaire', apres: $d + ['id' => $inventaire]);
        });

        return redirect()->route('inventaires.show', $inventaire)->with('succes', 'Paramètres enregistrés.');
    }

    public function show(int $inventaire): Response
    {
        $inv = Inventaires::campagne($inventaire);
        $site = Perimetre::site();
        $lignes = DB::table('inventaire_ligne as l')->join('site', 'site.id', '=', 'l.site_id')
            ->leftJoin('article as a', 'a.id', '=', 'l.article_id')->leftJoin('type_kit as k', 'k.id', '=', 'l.type_kit_id')
            ->leftJoin('categorie as c', 'c.id', '=', DB::raw('coalesce(a.categorie_id, k.categorie_id)'))
            ->where('l.inventaire_id', $inventaire)->when($site, fn ($q) => $q->where('l.site_id', $site))
            ->orderBy('site.nom')->orderByRaw('l.type_kit_id is null')->orderByRaw('coalesce(a.reference, k.reference)')
            ->get(['l.*', 'site.nom as site', 'c.libelle as categorie',
                DB::raw("coalesce(a.reference, 'Kit ' || k.reference) as reference"), DB::raw('coalesce(a.nom, k.nom) as nom'), DB::raw('a.est_serialise')]);

        return Inertia::render('Stock/Inventaires/Fiche', [
            'inventaire' => (array) $inv + [
                'createur' => DB::table('users')->where('id', $inv->cree_par)->value('name'),
                'cloture_par_nom' => $inv->cloture_par ? DB::table('users')->where('id', $inv->cloture_par)->value('name') : null,
            ],
            'sites' => DB::table('inventaire_site')->join('site', 'site.id', '=', 'inventaire_site.site_id')
                ->where('inventaire_id', $inventaire)->when($site, fn ($q) => $q->where('site.id', $site))->orderBy('site.nom')->get(['site.id', 'site.nom']),
            'perimetre' => $this->perimetreLisible($inv),
            'lignes' => $lignes,
            'inconnus' => DB::table('inventaire_comptage')->join('site', 'site.id', '=', 'inventaire_comptage.site_id')
                ->where('inventaire_id', $inventaire)->whereNotNull('code_lu')->get(['inventaire_comptage.code_lu', 'inventaire_comptage.tour', 'site.nom as site']),
            'comptes' => DB::table('inventaire_comptage')->where(['inventaire_id' => $inventaire, 'tour' => $inv->tour])->count(),
            'articles' => DB::table('article')->whereIn('id', $inv->statut === 'comptage' ? Inventaires::articles($inv) : [])->orderBy('reference')->get(['id', 'reference', 'nom', 'est_serialise']),
            'typesKit' => DB::table('type_kit')->whereIn('id', $inv->statut === 'comptage' ? Inventaires::typesKit($inv) : [])->orderBy('reference')->get(['id', 'reference', 'nom']),
        ]);
    }

    public function lancer(int $inventaire): RedirectResponse
    {
        DB::transaction(fn () => Inventaires::lancer($inventaire));
        Audit::enregistrer('lancer', 'inventaire', apres: ['id' => $inventaire]);

        return back()->with('succes', 'Inventaire lancé : le stock théorique est figé, le 1er comptage est ouvert.');
    }

    public function compter(Request $request, int $inventaire)
    {
        $d = $request->validate([
            'site_id' => ['required', 'integer'],
            'code' => ['nullable', 'string', 'max:64'],
            'article_id' => ['nullable', 'integer'],
            'type_kit_id' => ['nullable', 'integer'],
            'quantite' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        if (Perimetre::site() && Perimetre::site() !== (int) $d['site_id']) {
            return response()->json(['message' => 'Vous ne comptez que sur votre site.'], 422);
        }

        $r = DB::transaction(fn () => Inventaires::compter($inventaire, (int) $d['site_id'], $request->user()->id, $d['code'] ?? null,
            isset($d['article_id']) ? (int) $d['article_id'] : null, isset($d['type_kit_id']) ? (int) $d['type_kit_id'] : null,
            isset($d['quantite']) ? (int) $d['quantite'] : null, null));

        return response()->json($r);
    }

    public function terminerTour(int $inventaire): RedirectResponse
    {
        DB::transaction(fn () => Inventaires::terminerTour($inventaire));
        Audit::enregistrer('terminer_comptage', 'inventaire', apres: ['id' => $inventaire]);

        return back()->with('succes', 'Comptage terminé : les écarts sont calculés.');
    }

    public function secondComptage(Request $request, int $inventaire): RedirectResponse
    {
        $lignes = $request->validate(['lignes' => ['required', 'array', 'min:1'], 'lignes.*' => ['integer']])['lignes'];
        DB::transaction(fn () => Inventaires::lancerSecondComptage($inventaire, $lignes));
        Audit::enregistrer('lancer_second_comptage', 'inventaire', apres: ['id' => $inventaire, 'lignes' => $lignes]);

        return back()->with('succes', count($lignes).' ligne(s) à recompter : le 2e comptage est ouvert.');
    }

    public function decompte(int $inventaire): RedirectResponse
    {
        DB::transaction(fn () => Inventaires::ouvrirDecompte($inventaire));
        Audit::enregistrer('ouvrir_decompte', 'inventaire', apres: ['id' => $inventaire]);

        return back()->with('succes', 'Décompte final ouvert : vérifiez les quantités retenues.');
    }

    public function fixerFinal(Request $request, int $ligne): RedirectResponse
    {
        $d = $request->validate(['final' => ['required', 'integer', 'min:0'], 'justification' => ['nullable', 'string', 'max:500']]);
        DB::transaction(fn () => Inventaires::fixerFinal($ligne, (int) $d['final'], $d['justification'] ?? null));
        Audit::enregistrer('fixer_final', 'inventaire_ligne', apres: $d + ['ligne' => $ligne]);

        return back()->with('succes', 'Quantité finale enregistrée.');
    }

    public function cloturer(Request $request, int $inventaire): RedirectResponse
    {
        $ajuster = $request->boolean('ajuster');
        $bilan = DB::transaction(fn () => Inventaires::cloturer($inventaire, $request->user()->id, $ajuster));
        Audit::enregistrer('cloturer', 'inventaire', apres: $bilan + ['id' => $inventaire, 'stock_ajuste' => $ajuster]);

        return back()->with('succes', $ajuster
            ? "Inventaire clôturé et stock ajusté : {$bilan['lots_ajustes']} lot(s) ajusté(s), {$bilan['lots_crees']} lot(s) créé(s), {$bilan['series_perdues']} n° perdu(s), {$bilan['series_retrouvees']} retrouvé(s)."
            : 'Inventaire clôturé sans ajustement du stock.');
    }

    /** @return array<string, mixed> */
    private function valider(Request $request): array
    {
        return $request->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'date_prevue' => ['required', 'date'],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'sites' => ['required', 'array', 'min:1'],
            'sites.*' => ['integer', Rule::exists('site', 'id')],
            'tous_articles' => ['required', 'boolean'],
            'tous_kits' => ['required', 'boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', Rule::exists('categorie', 'id')],
            'articles' => ['nullable', 'array'],
            'articles.*' => ['integer', Rule::exists('article', 'id')],
            'types_kit' => ['nullable', 'array'],
            'types_kit.*' => ['integer', Rule::exists('type_kit', 'id')],
        ], ['sites.required' => 'Choisissez au moins un site.']);
    }

    private function enregistrerPerimetre(int $id, array $d): void
    {
        DB::table('inventaire_site')->where('inventaire_id', $id)->delete();
        DB::table('inventaire_cible')->where('inventaire_id', $id)->delete();
        foreach (array_unique($d['sites']) as $site) {
            DB::table('inventaire_site')->insert(['inventaire_id' => $id, 'site_id' => $site]);
        }
        foreach (['categorie' => 'categories', 'article' => 'articles', 'type_kit' => 'types_kit'] as $type => $champ) {
            foreach (array_unique($d[$champ] ?? []) as $cible) {
                DB::table('inventaire_cible')->insert(['inventaire_id' => $id, 'type' => $type, 'cible_id' => $cible]);
            }
        }
    }

    private function perimetreLisible(object $inv): array
    {
        $cibles = DB::table('inventaire_cible')->where('inventaire_id', $inv->id)->get()->groupBy('type');

        return array_values(array_filter([
            $inv->tous_articles ? 'Tous les articles' : null,
            $inv->tous_kits ? 'Tous les kits montés' : null,
            ...DB::table('categorie')->whereIn('id', ($cibles['categorie'] ?? collect())->pluck('cible_id'))->pluck('libelle')->map(fn ($l) => "Catégorie {$l}"),
            ...DB::table('article')->whereIn('id', ($cibles['article'] ?? collect())->pluck('cible_id'))->pluck('reference'),
            ...DB::table('type_kit')->whereIn('id', ($cibles['type_kit'] ?? collect())->pluck('cible_id'))->pluck('reference')->map(fn ($r) => "Kit {$r}"),
        ]));
    }

    /** @return array<string, mixed> */
    private function listes(): array
    {
        return [
            'sites' => DB::table('site')->where('actif', true)->orderByRaw("type = 'entrepot' desc")->orderBy('nom')->get(['id', 'nom', 'type']),
            'categories' => DB::table('categorie')->orderBy('libelle')->get(['id', 'libelle']),
            'articles' => DB::table('article')->orderBy('reference')->get(['id', 'reference', 'nom']),
            'typesKit' => DB::table('type_kit')->orderBy('reference')->get(['id', 'reference', 'nom']),
        ];
    }
}
