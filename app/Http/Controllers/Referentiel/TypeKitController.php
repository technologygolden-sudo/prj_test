<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Categorie;
use App\Models\Fabricant;
use App\Models\TypeKit;
use App\Models\VersionKit;
use App\Support\Audit;
use App\Support\Nomenclature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TypeKitController extends Controller
{
    public function index(Request $request): Response
    {
        $filtres = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'etat' => ['nullable', 'in:porteur,brouillon,actif,vide'],
        ]);
        $q = $filtres['q'] ?? null;

        $types = TypeKit::with(['versionActive', 'brouillon.porteur:id,reference', 'versionActive.porteur:id,reference', 'categorie:id,libelle'])
            ->withCount('versions')
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->whereLike('reference', "%{$q}%", caseSensitive: false)
                ->orWhereLike('nom', "%{$q}%", caseSensitive: false)
                ->orWhereLike('partno', "%{$q}%", caseSensitive: false)))
            ->when(($filtres['etat'] ?? null) === 'porteur', fn ($query) => $query->whereHas('brouillon', fn ($b) => $b->whereNull('article_porteur_id')))
            ->when(($filtres['etat'] ?? null) === 'brouillon', fn ($query) => $query->has('brouillon'))
            ->when(($filtres['etat'] ?? null) === 'actif', fn ($query) => $query->has('versionActive'))
            ->when(($filtres['etat'] ?? null) === 'vide', fn ($query) => $query->doesntHave('versions'))
            ->orderBy('reference')
            ->paginate(25)
            ->withQueryString()
            ->through(function (TypeKit $t) {
                $travail = $t->brouillon ?? $t->versionActive; // version de travail : le brouillon, sinon l'active

                return [
                    'id' => $t->id,
                    'reference' => $t->reference,
                    'partno' => $t->partno,
                    'nom' => $t->nom,
                    'categorie' => $t->categorie?->libelle,
                    'version_active' => $t->versionActive?->numero,
                    'brouillon' => $t->brouillon?->numero,
                    'versions_count' => $t->versions_count,
                    'porteur' => $travail?->porteur?->reference,
                    'articles' => $travail ? DB::table('composition')->where('version_kit_id', $travail->id)->count() : 0,
                ];
            });

        return Inertia::render('Referentiel/Kits/Index', ['types' => $types, 'filtres' => $filtres]);
    }

    public function create(): Response
    {
        return Inertia::render('Referentiel/Kits/Creer', $this->listes());
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate($this->reglesType() + Nomenclature::regles());

        $type = DB::transaction(function () use ($donnees) {
            $type = TypeKit::create(collect($donnees)->except(['porteur_id', 'lignes'])->all());
            $porteur = isset($donnees['porteur_id']) ? (int) $donnees['porteur_id'] : null;
            $version = $type->versions()->create(['numero' => 1, 'article_porteur_id' => $porteur]);
            Nomenclature::enregistrer($version, $porteur, $donnees['lignes'] ?? []);
            Audit::enregistrer('creer', $type, apres: $type->toArray());
            Audit::enregistrer('creer', $version, apres: Nomenclature::instantane($version));

            return $type;
        });

        return redirect()->route('kits.show', $type)->with('succes', "Type de kit {$type->reference} créé avec sa version 1 (brouillon).");
    }

    public function show(TypeKit $kit): Response
    {
        $kit->load(['categorie:id,libelle', 'fabricant:id,nom', 'versions.porteur:id,reference,nom', 'versions.articles:id,reference,nom,est_serialise', 'versions.verrouilleePar:id,name']);
        $active = $kit->versionActive()->value('id');

        return Inertia::render('Referentiel/Kits/Fiche', $this->listes() + [
            'kit' => $kit->only(['id', 'reference', 'partno', 'nom', 'categorie_id', 'fabricant_id', 'cout_unitaire']) + [
                'categorie' => $kit->categorie?->libelle,
                'fabricant' => $kit->fabricant?->nom,
            ],
            'versions' => $kit->versions->map(fn (VersionKit $v) => [
                'id' => $v->id,
                'numero' => $v->numero,
                'verrouillee' => $v->verrouillee,
                'active' => $v->id === $active,
                'verrouillee_le' => $v->verrouillee_le?->format('d/m/Y H:i'),
                'verrouillee_par' => $v->verrouilleePar?->name,
                'porteur_id' => $v->article_porteur_id,
                'porteur' => $v->porteur?->only(['id', 'reference', 'nom']),
                'lignes' => $v->articles
                    ->reject(fn (Article $a) => $a->id === $v->article_porteur_id)
                    ->sortBy('reference')->values()
                    ->map(fn (Article $a) => [
                        'article_id' => $a->id, 'reference' => $a->reference, 'nom' => $a->nom, 'quantite' => $a->pivot->quantite,
                    ]),
            ]),
            'kitsAssembles' => DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
                ->where('version_kit.type_kit_id', $kit->id)->count(),
        ]);
    }

    public function update(Request $request, TypeKit $kit): RedirectResponse
    {
        $donnees = $request->validate($this->reglesType($kit));
        $avant = $kit->toArray();
        $kit->update($donnees);
        Audit::enregistrer('modifier', $kit, $avant, $kit->toArray());

        return back()->with('succes', 'Type de kit modifié.');
    }

    public function destroy(TypeKit $kit): RedirectResponse
    {
        if ($kit->versions()->where('verrouillee', true)->exists()) {
            return back()->with('alerte', 'Ce type de kit a une version verrouillée : il ne peut plus être supprimé.');
        }

        DB::transaction(function () use ($kit) {
            Audit::enregistrer('supprimer', $kit, $kit->toArray());
            foreach ($kit->versions as $version) {
                DB::table('composition')->where('version_kit_id', $version->id)->delete();
                $version->delete();
            }
            $kit->delete();
        });

        return redirect()->route('kits.index')->with('succes', "Type de kit {$kit->reference} supprimé.");
    }

    /** @return array<string, mixed> */
    private function reglesType(?TypeKit $kit = null): array
    {
        return [
            'reference' => ['required', 'string', 'max:64', Rule::unique('type_kit', 'reference')->ignore($kit)],
            'partno' => ['required', 'string', 'max:64'],
            'nom' => ['required', 'string', 'max:255'],
            'categorie_id' => ['nullable', 'integer', Rule::exists('categorie', 'id')],
            'fabricant_id' => ['nullable', 'integer', Rule::exists('fabricant', 'id')],
            'cout_unitaire' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }

    /** @return array<string, mixed> */
    private function listes(): array
    {
        return [
            'categories' => Categorie::orderBy('libelle')->get(['id', 'libelle']),
            'fabricants' => Fabricant::orderBy('nom')->get(['id', 'nom']),
            'articles' => Article::orderBy('reference')->get(['id', 'reference', 'nom', 'est_serialise']),
        ];
    }
}
