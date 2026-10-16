<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Stock;
use App\Support\Uid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Réception des articles (EF03, EF04, RG07, RG26, processus 4.1). */
class ReceptionController extends Controller
{
    public function index(): Response
    {
        $receptions = DB::table('reception')
            ->leftJoin('users', 'users.id', '=', 'reception.utilisateur_id')
            ->select('reception.*', 'users.name as utilisateur')
            ->selectSub(fn ($q) => $q->from('unite_article')->whereColumn('reception_id', 'reception.id')->selectRaw('count(*)'), 'unites')
            ->orderByDesc('reception.id')
            ->paginate(25);

        return Inertia::render('Stock/Receptions/Index', ['receptions' => $receptions]);
    }

    public function create(): Response
    {
        return Inertia::render('Stock/Receptions/Creer', [
            'articles' => DB::table('article')->orderBy('reference')->get(['id', 'reference', 'nom', 'est_serialise', 'fabricant_id']),
            'imprimantes' => self::imprimantes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'num_bl' => ['required', 'string', 'max:64'],
            'fournisseur' => ['required', 'string', 'max:255'],
            'emplacement' => ['nullable', 'string', 'max:64'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.article_id' => ['required', 'integer', Rule::exists('article', 'id')],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:100000'],
            'lignes.*.series' => ['nullable', 'array', 'max:1000'],
            'lignes.*.series.*' => ['string', 'max:64', 'regex:/^[\x21-\x7E]+$/'],
        ], ['lignes.*.series.*.regex' => 'Un n° de série ne contient ni espace ni caractère accentué.']);

        $articles = DB::table('article')->whereIn('id', collect($donnees['lignes'])->pluck('article_id'))->get()->keyBy('id');
        $erreurs = [];
        $vus = [];
        foreach ($donnees['lignes'] as $i => $ligne) {
            $article = $articles[$ligne['article_id']];
            $series = array_values(array_unique(array_map('trim', $ligne['series'] ?? [])));
            if (! $article->est_serialise) {
                continue;
            }
            // RG26 : autant de n° scannés que la quantité de la ligne.
            if (count($series) !== (int) $ligne['quantite']) {
                $erreurs["lignes.{$i}.series"] = "{$article->reference} : ".count($series)." n° de série scanné(s) pour une quantité de {$ligne['quantite']}.";

                continue;
            }
            // RG07 : unicité par fabricant, dans le bon de livraison et en base.
            foreach ($series as $serie) {
                $cle = $article->fabricant_id.'|'.$serie;
                if (isset($vus[$cle])) {
                    $erreurs["lignes.{$i}.series"] = "Le n° {$serie} apparaît deux fois dans ce bon de livraison.";
                }
                $vus[$cle] = true;
            }
            $existants = DB::table('unite_article')->where('fabricant_id', $article->fabricant_id)->whereIn('numero_serie', $series)->pluck('numero_serie');
            if ($existants->isNotEmpty()) {
                $erreurs["lignes.{$i}.series"] = 'Déjà en base pour ce fabricant : '.$existants->implode(', ').'.';
            }
        }
        if ($erreurs) {
            throw ValidationException::withMessages($erreurs);
        }

        $receptionId = DB::transaction(function () use ($donnees, $articles, $request) {
            $userId = $request->user()->id;
            $receptionId = DB::table('reception')->insertGetId([
                'date' => $donnees['date'], 'num_bl' => $donnees['num_bl'], 'fournisseur' => $donnees['fournisseur'],
                'utilisateur_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($donnees['lignes'] as $ligne) {
                $article = $articles[$ligne['article_id']];
                $unites = $article->est_serialise
                    ? array_map(fn ($s) => ['nature' => 'serie', 'numero_serie' => trim($s), 'quantite' => 1, 'prefixe' => Uid::ARTICLE], array_values(array_unique($ligne['series'])))
                    : [['nature' => 'lot', 'numero_serie' => null, 'quantite' => (int) $ligne['quantite'], 'prefixe' => Uid::LOT]];

                foreach ($unites as $u) {
                    $id = DB::table('unite_article')->insertGetId([
                        'uid' => Uid::generer($u['prefixe']), 'nature' => $u['nature'], 'numero_serie' => $u['numero_serie'],
                        'quantite_initiale' => $u['quantite'], 'quantite_restante' => 0, 'statut' => 'en_stock',
                        'emplacement' => $donnees['emplacement'] ?? null, 'article_id' => $article->id, 'reception_id' => $receptionId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    Stock::mouvementUnite(Stock::verrouillerUnite($id), 'entree', $u['quantite'], $userId, null, ['reception_id' => $receptionId]);
                }
            }

            Audit::enregistrer('creer', 'reception', apres: $donnees + ['id' => $receptionId]);

            return $receptionId;
        });

        return redirect()->route('receptions.show', $receptionId)
            ->with('succes', 'Réception enregistrée. Imprimez maintenant les étiquettes.');
    }

    public function show(int $reception): Response
    {
        $entete = DB::table('reception')->leftJoin('users', 'users.id', '=', 'reception.utilisateur_id')
            ->where('reception.id', $reception)->select('reception.*', 'users.name as utilisateur')->first() ?? abort(404);

        return Inertia::render('Stock/Receptions/Fiche', [
            'reception' => $entete,
            'unites' => DB::table('unite_article')->join('article', 'article.id', '=', 'unite_article.article_id')
                ->where('reception_id', $reception)
                ->select('unite_article.id', 'uid', 'nature', 'numero_serie', 'quantite_initiale', 'quantite_restante', 'statut', 'article.reference', 'article.nom')
                ->selectSub(fn ($q) => $q->from('impression_etiquette')->whereColumn('unite_id', 'unite_article.id')->selectRaw('count(*)'), 'impressions')
                ->orderBy('article.reference')->orderBy('numero_serie')->get(),
            'imprimantes' => self::imprimantes(),
        ]);
    }

    public static function imprimantes()
    {
        return DB::table('imprimante')->where('actif', true)->orderBy('nom')->get(['id', 'nom', 'adresse'])
            ->map(fn ($i) => ['id' => $i->id, 'nom' => $i->nom, 'format' => $i->adresse === 'navigateur' ? 'PDF' : 'ZPL']);
    }
}
