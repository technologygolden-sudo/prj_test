<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Etiquettes\Imprimeur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Impression et réimpression motivée des étiquettes (EF04, EF06, RG12). */
class EtiquetteController extends Controller
{
    public function imprimer(Request $request, Imprimeur $imprimeur)
    {
        $donnees = $request->validate([
            'cibles' => ['required', 'array', 'min:1', 'max:500'],
            'cibles.*.type' => ['required', 'in:unite,kit'],
            'cibles.*.id' => ['required', 'integer'],
            'imprimante_id' => ['required', 'integer', Rule::exists('imprimante', 'id')],
            'copies' => ['nullable', 'integer', 'between:1,100'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        $reimpression = collect($donnees['cibles'])->contains(fn ($c) => DB::table('impression_etiquette')
            ->where($c['type'] === 'kit' ? 'kit_id' : 'unite_id', $c['id'])->exists());
        abort_if($reimpression && ! $request->user()->can('etiquettes.reimprimer'), 403, 'Réimpression non autorisée.');

        $resultat = $imprimeur->imprimer(
            array_map(fn ($c) => ['type' => $c['type'], 'id' => (int) $c['id']], $donnees['cibles']),
            (int) $donnees['imprimante_id'],
            $request->user()->id,
            (int) ($donnees['copies'] ?? 1),
            $donnees['motif'] ?? null,
        );

        if ($resultat['format'] === 'PDF') {
            return response($resultat['pdf'], 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="etiquettes.pdf"',
            ]);
        }

        return back()->with('succes', "{$resultat['impressions']} étiquette(s) envoyée(s) à l’imprimante.");
    }
}
