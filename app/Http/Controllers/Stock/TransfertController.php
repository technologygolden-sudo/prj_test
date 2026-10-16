<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Emplacements;
use App\Support\Perimetre;
use App\Support\Stock;
use App\Support\Transferts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Transferts entre l'entrepôt et les centres de service : bon de transfert puis réception validée. */
class TransfertController extends Controller
{
    public function index(Request $request): Response
    {
        $filtres = $request->validate(['statut' => ['nullable', 'in:en_transit,recu_partiel,recu'], 'site' => ['nullable', 'integer']]);

        return Inertia::render('Stock/Transferts/Index', [
            'transferts' => DB::table('transfert as t')
                ->join('site as o', 'o.id', '=', 't.site_origine_id')->join('site as d', 'd.id', '=', 't.site_destination_id')
                ->leftJoin('users as u', 'u.id', '=', 't.envoye_par')
                ->when(Perimetre::site(), fn ($q, $s) => $q->where(fn ($w) => $w->where('t.site_origine_id', $s)->orWhere('t.site_destination_id', $s)))
                ->when($filtres['statut'] ?? null, fn ($q, $s) => $q->where('t.statut', $s))
                ->when($filtres['site'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('t.site_origine_id', $s)->orWhere('t.site_destination_id', $s)))
                ->select('t.*', 'o.nom as origine', 'd.nom as destination', 'u.name as envoyeur')
                ->selectSub(fn ($q) => $q->from('transfert_ligne')->whereColumn('transfert_id', 't.id')->selectRaw('count(*)'), 'lignes')
                ->selectSub(fn ($q) => $q->from('transfert_ligne')->whereColumn('transfert_id', 't.id')->whereNotNull('recu_le')->selectRaw('count(*)'), 'recues')
                ->orderByRaw("t.statut = 'recu'")->orderByDesc('t.id')->paginate(25)->withQueryString(),
            'filtres' => $filtres,
            'sites' => DB::table('site')->where('actif', true)->orderByRaw("type = 'entrepot' desc")->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Stock/Transferts/Creer', [
            'sites' => DB::table('site')->where('actif', true)->orderByRaw("type = 'entrepot' desc")->orderBy('nom')->get(['id', 'nom', 'type']),
            'siteImpose' => Perimetre::site(), // un shop manager n'envoie que depuis son site
        ]);
    }

    /** Articles et types de kit disponibles au site de départ, pour préparer le bon (JSON). */
    public function disponibilites(Request $request)
    {
        $site = (int) $request->validate(['site_id' => ['required', 'integer']])['site_id'];
        if (Perimetre::site() && Perimetre::site() !== $site) {
            return response()->json(['message' => 'Vous ne pouvez envoyer que depuis votre site.'], 422);
        }

        return response()->json([
            'articles' => DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
                ->join('emplacement', 'emplacement.id', '=', 'unite_article.emplacement_id')
                ->where(['emplacement.site_id' => $site, 'unite_article.statut' => 'en_stock'])
                ->groupBy('article.id', 'article.reference', 'article.nom', 'article.est_serialise')->orderBy('article.reference')
                ->get(['article.id', 'article.reference', 'article.nom', 'article.est_serialise', DB::raw('sum(unite_article.quantite_restante)::int as disponible')]),
            'kits' => DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
                ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
                ->join('emplacement', 'emplacement.id', '=', 'kit_assemble.emplacement_id')
                ->where(['emplacement.site_id' => $site, 'kit_assemble.statut' => 'assemble'])
                ->groupBy('type_kit.id', 'type_kit.reference', 'type_kit.nom')->orderBy('type_kit.reference')
                ->get(['type_kit.id', 'type_kit.reference', 'type_kit.nom', DB::raw('count(*)::int as disponible')]),
        ]);
    }

    /** Contrôle d'un scan : l'élément doit être disponible sur le site de départ (JSON). */
    public function verifier(Request $request)
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:64'], 'site_id' => ['required', 'integer']]);
        if (Perimetre::site() && Perimetre::site() !== (int) $d['site_id']) {
            return response()->json(['message' => 'Vous ne pouvez envoyer que depuis votre site.'], 422);
        }
        $code = trim($d['code']);

        $kit = DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
            ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->where(fn ($q) => $q->where('kit_assemble.uid', strtoupper($code))->orWhere('kit_assemble.numero_serie', $code))
            ->where('kit_assemble.statut', 'assemble')
            ->first(['kit_assemble.*', 'type_kit.reference', 'type_kit.id as type_kit_id']);
        if ($kit) {
            return $this->reponse('kit', $kit, "Kit {$kit->reference} · S/N {$kit->numero_serie}", $d['site_id']);
        }

        $unites = array_values(array_filter(Stock::trouverUnites($code), fn ($u) => $u->statut === 'en_stock'));
        if (count($unites) !== 1) {
            return response()->json(['message' => $unites ? 'Plusieurs unités portent ce n° (fabricants différents) : ouvrez la fiche pour choisir.' : Stock::aideScan($code, 'Aucun élément disponible pour ce code.')], 422);
        }
        $u = $unites[0];
        $article = DB::table('article')->where('id', $u->article_id)->value('reference');

        return $this->reponse('unite', $u, $article.' · '.($u->numero_serie ? "S/N {$u->numero_serie}" : "lot de {$u->quantite_restante}"), $d['site_id']);
    }

    private function reponse(string $type, object $ligne, string $libelle, int $siteId)
    {
        $e = EmplacementController::libelle($ligne->emplacement_id);
        if (! $e || $e['site_id'] !== $siteId) {
            return response()->json(['message' => "{$libelle} n’est pas sur le site de départ".($e ? " (il est à {$e['libelle']})." : '.')], 422);
        }

        return response()->json([
            'type' => $type, 'id' => $ligne->id, 'uid' => $ligne->uid, 'libelle' => $libelle, 'emplacement' => $e['libelle'],
            'etat' => $ligne->etat, 'proprietaire' => $ligne->proprietaire,
            'max' => $type === 'unite' ? $ligne->quantite_restante : 1, 'lot' => $type === 'unite' && $ligne->nature === 'lot',
            // Ligne du bon à laquelle rattacher le scan : l'article, ou le type de kit.
            'cle' => $type === 'kit' ? "kit:{$ligne->type_kit_id}" : "article:{$ligne->article_id}",
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'site_origine_id' => ['required', 'integer', Rule::exists('site', 'id')->where('actif', true)],
            'site_destination_id' => ['required', 'integer', 'different:site_origine_id', Rule::exists('site', 'id')->where('actif', true)],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
            'lignes' => ['required', 'array', 'min:1', 'max:500'],
            'lignes.*.type' => ['required', 'in:unite,kit'],
            'lignes.*.id' => ['required', 'integer'],
            'lignes.*.quantite' => ['nullable', 'integer', 'min:1'],
            // RG34 : quantités définies avant le scan, par article ou type de kit.
            'prevu' => ['required', 'array', 'min:1', 'max:200'],
            'prevu.*.cle' => ['required', 'string', 'regex:/^(article|kit):\d+$/', 'distinct'],
            'prevu.*.quantite' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        if (Perimetre::site() && Perimetre::site() !== (int) $d['site_origine_id']) {
            return back()->with('alerte', 'Vous ne pouvez envoyer que depuis votre site (RG33).');
        }

        // Les éléments scannés doivent correspondre exactement au bon.
        $scanne = [];
        foreach ($d['lignes'] as $l) {
            if ($l['type'] === 'kit') {
                $type = DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')->where('kit_assemble.id', $l['id'])->value('version_kit.type_kit_id');
                $cle = "kit:{$type}";
                $quantite = 1;
            } else {
                $u = DB::table('unite_article')->where('id', $l['id'])->first(['article_id', 'nature', 'quantite_restante']);
                $cle = "article:{$u?->article_id}";
                $quantite = $u?->nature === 'lot' ? min((int) ($l['quantite'] ?? $u->quantite_restante), (int) $u->quantite_restante) : 1;
            }
            $scanne[$cle] = ($scanne[$cle] ?? 0) + $quantite;
        }
        $prevu = collect($d['prevu'])->mapWithKeys(fn ($p) => [$p['cle'] => (int) $p['quantite']])->all();
        ksort($scanne);
        ksort($prevu);
        if ($scanne !== $prevu) {
            return back()->withErrors(['lignes' => 'Les éléments scannés ne correspondent pas aux quantités du bon de transfert.']);
        }
        $id = DB::transaction(function () use ($d, $request) {
            $id = Transferts::envoyer((int) $d['site_origine_id'], (int) $d['site_destination_id'], $d['motif'], $d['lignes'], $request->user()->id);
            DB::table('transfert')->where('id', $id)->update(['quantites_prevues' => json_encode($d['prevu'])]);

            return $id;
        });

        return redirect()->route('transferts.show', $id)->with('succes', "Transfert n° {$id} envoyé : les éléments sont en transit jusqu’à la validation de la réception.");
    }

    public function show(int $transfert): Response
    {
        $t = DB::table('transfert as t')->join('site as o', 'o.id', '=', 't.site_origine_id')->join('site as d', 'd.id', '=', 't.site_destination_id')
            ->leftJoin('users as e', 'e.id', '=', 't.envoye_par')->leftJoin('users as r', 'r.id', '=', 't.recu_par')
            ->where('t.id', $transfert)->first(['t.*', 'o.nom as origine', 'd.nom as destination', 'e.name as envoyeur', 'r.name as receveur']) ?? abort(404);
        $site = Perimetre::site();
        abort_if($site && ! in_array($site, [$t->site_origine_id, $t->site_destination_id], true), 403, 'Ce transfert ne concerne pas votre site.');

        $lignes = DB::table('transfert_ligne as l')
            ->leftJoin('unite_article as u', 'u.id', '=', 'l.unite_id')->leftJoin('article as a', 'a.id', '=', 'u.article_id')
            ->leftJoin('kit_assemble as k', 'k.id', '=', 'l.kit_id')->leftJoin('version_kit as v', 'v.id', '=', 'k.version_kit_id')->leftJoin('type_kit as tk', 'tk.id', '=', 'v.type_kit_id')
            ->leftJoin('emplacement as ea', 'ea.id', '=', 'l.emplacement_arrivee_id')
            ->where('l.transfert_id', $transfert)->orderBy('l.id')
            ->get(['l.id', 'l.unite_id', 'l.kit_id', 'l.recu_le', 'ea.nom as arrivee',
                DB::raw('coalesce(u.uid, k.uid) as uid'), DB::raw('coalesce(u.etat, k.etat) as etat'), DB::raw('coalesce(u.proprietaire, k.proprietaire) as proprietaire'),
                DB::raw("coalesce(a.reference || coalesce(' · S/N ' || u.numero_serie, ' · lot de ' || u.quantite_restante), 'Kit ' || tk.reference || ' · S/N ' || k.numero_serie) as libelle")]);

        return Inertia::render('Stock/Transferts/Fiche', [
            'transfert' => $t,
            'lignes' => $lignes,
            'emplacements' => Emplacements::liste($t->site_destination_id),
            'peutRecevoir' => Perimetre::peutRecevoir($t->site_destination_id),
        ]);
    }

    public function recevoir(Request $request, int $transfert): RedirectResponse
    {
        $d = $request->validate([
            'arrivees' => ['required', 'array', 'min:1'],
            'arrivees.*' => ['required', 'integer'],
        ]);

        $destination = DB::table('transfert')->where('id', $transfert)->value('site_destination_id') ?? abort(404);
        if (! Perimetre::peutRecevoir($destination)) {
            return back()->with('alerte', 'Seul un utilisateur du site d’arrivée peut valider cette réception.');
        }
        $statut = DB::transaction(fn () => Transferts::recevoir($transfert, collect($d['arrivees'])->mapWithKeys(fn ($e, $l) => [(int) $l => (int) $e])->all(), $request->user()->id));

        return back()->with('succes', $statut === 'recu' ? 'Transfert entièrement reçu.' : 'Réception partielle enregistrée : les éléments manquants restent en transit.');
    }
}
