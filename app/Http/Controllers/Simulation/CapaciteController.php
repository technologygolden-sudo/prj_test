<?php

namespace App\Http\Controllers\Simulation;

use App\Http\Controllers\Controller;
use App\Support\Simulation\Calculateur;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** EF10 : capacité maximale de chaque type de kit seul, sur le stock réel, avec l'article limitant. */
class CapaciteController extends Controller
{
    public function __invoke(): Response
    {
        $versions = Calculateur::versionsSimulables();
        $compositions = Calculateur::compositions($versions->pluck('id')->all());
        $articleIds = collect($compositions)->flatMap(fn ($c) => array_keys($c))->unique()->all();
        $stock = Calculateur::stockReel($articleIds);
        $references = DB::table('article')->whereIn('id', $articleIds)->pluck('reference', 'id');

        return Inertia::render('Simulation/Capacite', [
            'lignes' => $versions->map(function ($v) use ($compositions, $stock, $references) {
                $cap = Calculateur::capacite($compositions[$v->id] ?? [], $stock);

                return [
                    'reference' => $v->reference, 'nom' => $v->nom, 'version' => $v->numero, 'brouillon' => ! $v->verrouillee,
                    'capacite' => $cap['capacite'],
                    'limitant' => $cap['limitant'] ? $references[$cap['limitant']] ?? null : null,
                    'stock_limitant' => $cap['limitant'] ? ($stock[$cap['limitant']] ?? 0) : null,
                ];
            })->values(),
        ]);
    }
}
