<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Models\TypeKit;
use App\Support\Audit;
use App\Support\Simulation\Calculateur;
use App\Support\Simulation\ClientOptimiseur;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Simulations de composition (EF08 à EF11, RG13) : elles ne modifient jamais le stock. */
class SimulationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Simulation/Index', [
            'simulations' => DB::table('simulation')->leftJoin('users', 'users.id', '=', 'simulation.utilisateur_id')
                ->select('simulation.*', 'users.name as utilisateur')
                ->selectSub(fn ($q) => $q->from('sim_demande')->whereColumn('simulation_id', 'simulation.id')->selectRaw('sum(qte_voulue)'), 'voulus')
                ->selectSub(fn ($q) => $q->from('sim_demande')->whereColumn('simulation_id', 'simulation.id')->selectRaw('sum(qte_realisable)'), 'realisables')
                ->orderByDesc('simulation.id')->paginate(25),
        ]);
    }

    public function create(): Response
    {
        $versions = Calculateur::versionsSimulables();
        $compositions = Calculateur::compositions($versions->pluck('id')->all());
        $articleIds = collect($compositions)->flatMap(fn ($c) => array_keys($c))->unique()->values()->all();

        return Inertia::render('Simulation/Creer', [
            'kits' => $versions->map(fn ($v) => [
                'version_id' => $v->id,
                'libelle' => "{$v->reference} · {$v->nom} (v{$v->numero}".($v->verrouillee ? '' : ', brouillon').')',
                'categorie' => $v->categorie,
                'articles' => array_keys($compositions[$v->id] ?? []),
            ])->values(),
            'articles' => DB::table('article')->whereIn('id', $articleIds)->orderBy('reference')->get(['id', 'reference', 'nom']),
            'stockReel' => Calculateur::stockReel($articleIds),
        ]);
    }

    public function store(Request $request, ClientOptimiseur $optimiseur): RedirectResponse
    {
        $donnees = $request->validate([
            'mode' => ['required', 'in:priorite,equilibre,volume'],
            'source_stock' => ['required', 'in:saisie,import,reel'],
            'demandes' => ['required', 'array', 'min:1', 'max:200'],
            // Version active, ou brouillon pour un kit pas encore verrouillé : la simulation n'a pas besoin du porteur.
            'demandes.*.version_id' => ['required', 'integer', 'distinct', Rule::in(Calculateur::versionsSimulables()->pluck('id')->all())],
            'demandes.*.qte' => ['required', 'integer', 'min:0', 'max:1000000'],
            'demandes.*.rang' => ['required', 'integer', 'min:1', 'max:1000'],
            'stock' => ['nullable', 'array', 'max:5000'],
            'stock.*' => ['integer', 'min:0', 'max:100000000'],
        ]);

        $versions = array_map('intval', array_column($donnees['demandes'], 'version_id'));
        $compositions = Calculateur::compositions($versions);
        $articleIds = collect($compositions)->flatMap(fn ($c) => array_keys($c))->unique()->values()->all();

        // Le stock vient de la saisie, d'un import ou du stock réel en lecture seule (RG13).
        $stock = $donnees['source_stock'] === 'reel'
            ? Calculateur::stockReel($articleIds)
            : collect($donnees['stock'] ?? [])->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q])->all();
        $stock = collect($articleIds)->mapWithKeys(fn ($id) => [$id => (int) ($stock[$id] ?? 0)])->all();

        $kits = array_map(fn ($d) => [
            'id' => (int) $d['version_id'], 'demande' => (int) $d['qte'], 'rang' => (int) $d['rang'],
            'composition' => $compositions[(int) $d['version_id']] ?? [],
        ], $donnees['demandes']);

        try {
            $resultat = $optimiseur->resoudre($donnees['mode'], $kits, $stock);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['mode' => $e->getMessage()]);
        }

        $quantites = $resultat['quantites'];
        $besoins = Calculateur::besoins($compositions, collect($kits)->pluck('demande', 'id')->all());
        $consommes = Calculateur::besoins($compositions, $quantites);

        $simulationId = DB::transaction(function () use ($request, $donnees, $resultat, $kits, $stock, $besoins, $consommes, $quantites) {
            $id = DB::table('simulation')->insertGetId([
                'mode' => $donnees['mode'], 'source_stock' => $donnees['source_stock'], 'statut' => $resultat['statut'],
                'optimalite' => $resultat['optimalite'], 'duree_ms' => $resultat['duree_ms'], 'utilisateur_id' => $request->user()->id,
            ]);
            foreach ($kits as $k) {
                DB::table('sim_demande')->insert([
                    'simulation_id' => $id, 'version_kit_id' => $k['id'], 'qte_voulue' => $k['demande'], 'rang_priorite' => $k['rang'],
                    'qte_realisable' => $resultat['statut'] === 'termine' ? min($k['demande'], $quantites[$k['id']] ?? 0) : null,
                ]);
            }
            foreach ($stock as $article => $disponible) {
                $consomme = $consommes[$article] ?? 0;
                DB::table('sim_stock')->insert([
                    'simulation_id' => $id, 'article_id' => $article, 'qte_disponible' => $disponible,
                    'qte_consommee' => $consomme, 'qte_manquante' => max(0, ($besoins[$article] ?? 0) - $disponible),
                    'qte_reliquat' => $disponible - $consomme,
                ]);
            }
            Audit::enregistrer('simuler', 'simulation', apres: ['id' => $id, 'mode' => $donnees['mode'], 'optimalite' => $resultat['optimalite']]);

            return $id;
        });

        return redirect()->route('simulations.show', $simulationId);
    }

    public function show(int $simulation): Response
    {
        return Inertia::render('Simulation/Resultat', $this->resultat($simulation));
    }

    /** EF11 : export du résultat (CSV lisible par Excel) ou de la liste d'achats des manquants. */
    public function exporter(int $simulation, string $format): StreamedResponse|\Illuminate\Http\Response
    {
        $r = $this->resultat($simulation);

        if ($format === 'pdf') {
            return Pdf::loadView('simulations.pdf', $r)->setPaper('a4')->download("simulation-{$simulation}.pdf");
        }

        $achats = $format === 'achats';
        $lignes = $achats
            ? [['Référence', 'Désignation', 'Quantité à acheter'], ...collect($r['articles'])->where('manquant', '>', 0)->map(fn ($a) => [$a['reference'], $a['nom'], $a['manquant']])]
            : [
                ['Kit', 'Rang', 'Voulu', 'Réalisable', 'Capacité seul', 'Article limitant'],
                ...collect($r['kits'])->map(fn ($k) => [$k['libelle'], $k['rang'], $k['voulu'], $k['realisable'], $k['capacite'], $k['limitant']]),
                [],
                ['Article', 'Désignation', 'Disponible', 'Besoin', 'Consommé', 'Manquant', 'Reliquat'],
                ...collect($r['articles'])->map(fn ($a) => [$a['reference'], $a['nom'], $a['disponible'], $a['besoin'], $a['consomme'], $a['manquant'], $a['reliquat']]),
            ];

        return response()->streamDownload(function () use ($lignes) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8
            foreach ($lignes as $ligne) {
                fputcsv($sortie, $ligne, ';', '"', '');
            }
            fclose($sortie);
        }, ($achats ? 'liste-achats' : 'simulation')."-{$simulation}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function resultat(int $id): array
    {
        $s = DB::table('simulation')->leftJoin('users', 'users.id', '=', 'simulation.utilisateur_id')
            ->where('simulation.id', $id)->first(['simulation.*', 'users.name as utilisateur']) ?? abort(404);

        $demandes = DB::table('sim_demande')->join('version_kit', 'version_kit.id', '=', 'sim_demande.version_kit_id')
            ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->where('simulation_id', $id)->orderBy('rang_priorite')->orderBy('type_kit.reference')
            ->get(['sim_demande.*', 'type_kit.reference', 'type_kit.nom', 'version_kit.numero']);
        $stocks = DB::table('sim_stock')->join('article', 'article.id', '=', 'sim_stock.article_id')
            ->where('simulation_id', $id)->orderBy('article.reference')
            ->get(['sim_stock.*', 'article.reference', 'article.nom']);

        $compositions = Calculateur::compositions($demandes->pluck('version_kit_id')->all());
        $disponible = $stocks->pluck('qte_disponible', 'article_id')->map(fn ($q) => (int) $q)->all();
        $besoins = Calculateur::besoins($compositions, $demandes->pluck('qte_voulue', 'version_kit_id')->map(fn ($q) => (int) $q)->all());
        $references = $stocks->pluck('reference', 'article_id');

        return [
            'simulation' => $s,
            'kits' => $demandes->map(function ($d) use ($compositions, $disponible, $references) {
                $cap = Calculateur::capacite($compositions[$d->version_kit_id] ?? [], $disponible);

                return [
                    'libelle' => "{$d->reference} · {$d->nom} (v{$d->numero})", 'rang' => $d->rang_priorite,
                    'voulu' => $d->qte_voulue, 'realisable' => $d->qte_realisable,
                    'capacite' => $cap['capacite'], 'limitant' => $cap['limitant'] ? $references[$cap['limitant']] ?? null : null,
                ];
            })->values(),
            'articles' => $stocks->map(fn ($a) => [
                'reference' => $a->reference, 'nom' => $a->nom, 'disponible' => $a->qte_disponible,
                'besoin' => $besoins[$a->article_id] ?? 0, 'consomme' => $a->qte_consommee,
                'manquant' => $a->qte_manquante, 'reliquat' => $a->qte_reliquat,
            ])->values(),
        ];
    }
}
