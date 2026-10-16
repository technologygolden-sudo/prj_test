<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Models\Fabricant;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FabricantController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Referentiel/ListeSimple', [
            'titre' => 'Fabricants',
            'champ' => 'nom',
            'libelleChamp' => 'Nom',
            'route' => 'fabricants',
            'elements' => Fabricant::withCount(['articles', 'typesKit'])->orderBy('nom')->get()
                ->map(fn (Fabricant $f) => [
                    'id' => $f->id,
                    'valeur' => $f->nom,
                    'utilisations' => $f->articles_count + $f->types_kit_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'valeur' => ['required', 'string', 'max:255', Rule::unique('fabricant', 'nom')],
        ]);
        $fabricant = Fabricant::create(['nom' => $donnees['valeur']]);
        Audit::enregistrer('creer', $fabricant, apres: $fabricant->toArray());

        return back()->with('succes', "Fabricant « {$fabricant->nom} » créé.");
    }

    public function update(Request $request, Fabricant $fabricant): RedirectResponse
    {
        $donnees = $request->validate([
            'valeur' => ['required', 'string', 'max:255', Rule::unique('fabricant', 'nom')->ignore($fabricant)],
        ]);
        $avant = $fabricant->toArray();
        $fabricant->update(['nom' => $donnees['valeur']]);
        Audit::enregistrer('modifier', $fabricant, $avant, $fabricant->toArray());

        return back()->with('succes', 'Fabricant modifié.');
    }

    public function destroy(Fabricant $fabricant): RedirectResponse
    {
        if ($fabricant->articles()->exists() || $fabricant->typesKit()->exists()) {
            return back()->with('alerte', 'Ce fabricant est utilisé : il ne peut pas être supprimé.');
        }
        Audit::enregistrer('supprimer', $fabricant, $fabricant->toArray());
        $fabricant->delete();

        return back()->with('succes', 'Fabricant supprimé.');
    }
}
