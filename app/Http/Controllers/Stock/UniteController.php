<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
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
            'statut' => ['nullable', 'in:'.implode(',', self::STATUTS)],
        ]);

        $unites = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->when($filtres['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('uid', strtoupper($t))->orWhere('numero_serie', 'ilike', "%{$t}%")
                ->orWhere('article.reference', 'ilike', "%{$t}%")->orWhere('article.nom', 'ilike', "%{$t}%")))
            ->when($filtres['statut'] ?? null, fn ($q, $s) => $q->where('statut', $s))
            ->select('unite_article.id', 'uid', 'nature', 'numero_serie', 'quantite_initiale', 'quantite_restante', 'statut', 'emplacement', 'article.reference', 'article.nom')
            ->orderByDesc('unite_article.id')
            ->paginate(30)->withQueryString();

        $synthese = DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
            ->where('statut', 'en_stock')
            ->groupBy('article.id', 'article.reference', 'article.nom')
            ->orderBy('article.reference')
            ->get(['article.reference', 'article.nom', DB::raw('sum(quantite_restante) as quantite')]);

        return Inertia::render('Stock/Unites/Index', ['unites' => $unites, 'filtres' => $filtres, 'synthese' => $synthese]);
    }

    public function show(int $unite): Response
    {
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
