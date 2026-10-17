<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Categorie;
use App\Models\Fabricant;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ArticleController extends Controller
{
    public function index(Request $request): Response
    {
        $filtres = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categorie' => ['nullable', 'integer'],
            'serialise' => ['nullable', 'in:oui,non'],
        ]);

        $articles = Article::with(['categorie:id,libelle', 'fabricant:id,nom'])
            // Tag « porteur de série » : l'article donne son n° de série à au moins un kit.
            ->withExists(['porteurDe as est_porteur'])
            ->selectSub(fn ($s) => $s->from('composition')->join('version_kit', 'version_kit.id', '=', 'composition.version_kit_id')
                ->whereColumn('composition.article_id', 'article.id')->selectRaw('count(distinct version_kit.type_kit_id)'), 'kits_count')
            ->when($filtres['q'] ?? null, fn ($q, $texte) => $q->where(fn ($q) => $q
                ->whereLike('reference', "%{$texte}%", caseSensitive: false)
                ->orWhereLike('nom', "%{$texte}%", caseSensitive: false)))
            ->when($filtres['categorie'] ?? null, fn ($q, $id) => $q->where('categorie_id', $id))
            ->when($filtres['serialise'] ?? null, fn ($q, $s) => $q->where('est_serialise', $s === 'oui'))
            ->orderBy('reference')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Referentiel/Articles/Index', [
            'articles' => $articles,
            'filtres' => $filtres,
            'categories' => Categorie::orderBy('libelle')->get(['id', 'libelle']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Referentiel/Articles/Formulaire', $this->listes() + ['article' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request);

        $article = DB::transaction(function () use ($donnees) {
            $article = Article::create($donnees);
            $article->associes()->sync($donnees['associes'] ?? []);
            Audit::enregistrer('creer', $article, apres: $article->toArray() + ['associes' => $donnees['associes'] ?? []]);

            return $article;
        });

        return redirect()->route('articles.index')->with('succes', "Article {$article->reference} créé.");
    }

    public function edit(Article $article): Response
    {
        $article->load('associes:id');

        return Inertia::render('Referentiel/Articles/Formulaire', $this->listes() + [
            'article' => $article->only(['id', 'reference', 'nom', 'type', 'est_serialise', 'categorie_id', 'fabricant_id', 'cout_unitaire'])
                + ['associes' => $article->associes->pluck('id')],
        ]);
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $donnees = $this->valider($request, $article);
        $avant = $article->toArray() + ['associes' => $article->associes()->pluck('article.id')->all()];

        try {
            DB::transaction(function () use ($article, $donnees, $avant) {
                $article->update($donnees);
                $article->associes()->sync($donnees['associes'] ?? []);
                Audit::enregistrer('modifier', $article, $avant, $article->toArray() + ['associes' => $donnees['associes'] ?? []]);
            });
        } catch (QueryException $e) {
            // RG05 : un article porteur de série d'un kit doit rester sérialisé (trigger en base).
            return back()->with('alerte', str_contains($e->getMessage(), 'RG05')
                ? 'Cet article est porteur de série d’un kit : il doit rester sérialisé.'
                : 'La modification a été refusée par la base de données.');
        }

        return redirect()->route('articles.index')->with('succes', "Article {$article->reference} modifié.");
    }

    public function destroy(Article $article): RedirectResponse
    {
        $utilise = DB::table('composition')->where('article_id', $article->id)->exists()
            || DB::table('version_kit')->where('article_porteur_id', $article->id)->exists()
            || DB::table('unite_article')->where('article_id', $article->id)->exists();

        if ($utilise) {
            return back()->with('alerte', "L'article {$article->reference} est utilisé dans un kit ou en stock : il ne peut pas être supprimé.");
        }

        DB::transaction(function () use ($article) {
            Audit::enregistrer('supprimer', $article, $article->toArray());
            $article->associes()->detach();
            DB::table('article_associe')->where('article_associe_id', $article->id)->delete();
            $article->delete();
        });

        return redirect()->route('articles.index')->with('succes', "Article {$article->reference} supprimé.");
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, ?Article $article = null): array
    {
        return $request->validate([
            'reference' => ['required', 'string', 'max:64', Rule::unique('article', 'reference')->ignore($article)],
            'nom' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:64'],
            'est_serialise' => ['required', 'boolean'],
            'categorie_id' => ['nullable', 'integer', Rule::exists('categorie', 'id')],
            // RG07 : le n° de série est unique par fabricant, un article sérialisé a donc un fabricant.
            'fabricant_id' => ['nullable', 'required_if_accepted:est_serialise', 'integer', Rule::exists('fabricant', 'id')],
            'cout_unitaire' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'associes' => ['nullable', 'array'],
            'associes.*' => ['integer', 'distinct', Rule::exists('article', 'id'), Rule::notIn([$article?->id])],
        ], [
            'fabricant_id.required_if_accepted' => 'Un article sérialisé doit avoir un fabricant (unicité du n° de série).',
        ], [
            'reference' => 'référence CRM',
            'nom' => 'désignation',
        ]);
    }

    /** @return array<string, mixed> */
    private function listes(): array
    {
        return [
            'categories' => Categorie::orderBy('libelle')->get(['id', 'libelle']),
            'fabricants' => Fabricant::orderBy('nom')->get(['id', 'nom']),
            'tousArticles' => Article::orderBy('reference')->get(['id', 'reference', 'nom']),
        ];
    }
}
