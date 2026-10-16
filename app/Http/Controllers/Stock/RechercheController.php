<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Recherche par scan ou saisie (EF07) : UID, n° de série ou référence.
 * Un résultat unique ouvre directement la fiche ; un n° partagé par un article et son kit affiche les deux.
 */
class RechercheController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $resultats = [];

        if ($q !== '') {
            $code = strtoupper($q);
            foreach (DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
                ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
                ->where(fn ($w) => $w->where('kit_assemble.uid', $code)->orWhere('kit_assemble.numero_serie', $q))
                ->get(['kit_assemble.id', 'kit_assemble.uid', 'kit_assemble.numero_serie', 'kit_assemble.statut', 'type_kit.reference']) as $k) {
                $resultats[] = ['type' => 'Kit assemblé', 'libelle' => "{$k->reference} · S/N {$k->numero_serie}", 'detail' => $k->uid, 'statut' => $k->statut, 'url' => "/kits-assembles/{$k->id}"];
            }
            foreach (DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
                ->where(fn ($w) => $w->where('uid', $code)->orWhere('numero_serie', $q))
                ->get(['unite_article.id', 'uid', 'numero_serie', 'statut', 'nature', 'article.reference']) as $u) {
                $resultats[] = ['type' => $u->nature === 'serie' ? 'Article sérialisé' : 'Lot', 'libelle' => $u->reference.($u->numero_serie ? " · S/N {$u->numero_serie}" : ''), 'detail' => $u->uid, 'statut' => $u->statut, 'url' => "/stock/{$u->id}"];
            }
            foreach (DB::table('article')->where('reference', 'ilike', $q)->get(['id', 'reference', 'nom']) as $a) {
                $resultats[] = ['type' => 'Article', 'libelle' => "{$a->reference} · {$a->nom}", 'detail' => null, 'statut' => null, 'url' => '/stock?q='.urlencode($a->reference)];
            }
        }

        if (count($resultats) === 1) {
            return redirect($resultats[0]['url']);
        }

        return Inertia::render('Stock/Recherche', ['q' => $q, 'resultats' => $resultats]);
    }
}
