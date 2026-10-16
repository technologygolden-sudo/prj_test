<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategorieController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Referentiel/ListeSimple', [
            'titre' => 'Catégories',
            'champ' => 'libelle',
            'libelleChamp' => 'Libellé',
            'route' => 'categories',
            'elements' => Categorie::withCount(['articles', 'typesKit'])->orderBy('libelle')->get()
                ->map(fn (Categorie $c) => [
                    'id' => $c->id,
                    'valeur' => $c->libelle,
                    'utilisations' => $c->articles_count + $c->types_kit_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'valeur' => ['required', 'string', 'max:255', Rule::unique('categorie', 'libelle')],
        ]);
        $categorie = Categorie::create(['libelle' => $donnees['valeur']]);
        Audit::enregistrer('creer', $categorie, apres: $categorie->toArray());

        return back()->with('succes', "Catégorie « {$categorie->libelle} » créée.");
    }

    public function update(Request $request, Categorie $categorie): RedirectResponse
    {
        $donnees = $request->validate([
            'valeur' => ['required', 'string', 'max:255', Rule::unique('categorie', 'libelle')->ignore($categorie)],
        ]);
        $avant = $categorie->toArray();
        $categorie->update(['libelle' => $donnees['valeur']]);
        Audit::enregistrer('modifier', $categorie, $avant, $categorie->toArray());

        return back()->with('succes', 'Catégorie modifiée.');
    }

    public function destroy(Categorie $categorie): RedirectResponse
    {
        if ($categorie->articles()->exists() || $categorie->typesKit()->exists()) {
            return back()->with('alerte', 'Cette catégorie est utilisée : elle ne peut pas être supprimée.');
        }
        Audit::enregistrer('supprimer', $categorie, $categorie->toArray());
        $categorie->delete();

        return back()->with('succes', 'Catégorie supprimée.');
    }
}
