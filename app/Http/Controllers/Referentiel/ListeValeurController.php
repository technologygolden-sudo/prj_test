<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Listes de valeurs du référentiel : CRUD complet, valeurs proposées dans les formulaires. */
class ListeValeurController extends Controller
{
    /** code => [titre, aide] */
    public const LISTES = [
        'type_article' => ['Types d’article', 'Proposés dans le champ « Type » de la fiche article.'],
        'motif_deplacement' => ['Motifs de déplacement', 'Proposés lors d’un déplacement entre emplacements ou d’un transfert.'],
        'motif_defaut' => ['Motifs de défaut', 'Proposés au SAV lors de la déclaration d’un défaut.'],
        'motif_reimpression' => ['Motifs de réimpression', 'Proposés lors de la réimpression d’une étiquette.'],
        'motif_ajustement' => ['Motifs d’ajustement', 'Proposés lors d’un ajustement d’inventaire.'],
        'technicien' => ['Techniciens', 'Proposés lors d’une remise de matériel à un technicien.'],
    ];

    public function index(Request $request): Response
    {
        $liste = $request->query('liste');
        $liste = array_key_exists($liste, self::LISTES) ? $liste : 'type_article';

        return Inertia::render('Referentiel/ListesValeurs', [
            'listes' => collect(self::LISTES)->map(fn ($l, $code) => [
                'code' => $code, 'titre' => $l[0], 'aide' => $l[1],
                'nombre' => DB::table('liste_valeur')->where('liste', $code)->count(),
            ])->values(),
            'liste' => $liste,
            'valeurs' => DB::table('liste_valeur')->where('liste', $liste)->orderBy('ordre')->orderBy('libelle')->get(['id', 'libelle', 'actif', 'ordre']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'liste' => ['required', Rule::in(array_keys(self::LISTES))],
            'libelle' => ['required', 'string', 'max:255', Rule::unique('liste_valeur')->where('liste', $request->input('liste'))],
        ]);
        $id = DB::table('liste_valeur')->insertGetId($d + [
            'ordre' => (int) DB::table('liste_valeur')->where('liste', $d['liste'])->max('ordre') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::enregistrer('creer', 'liste_valeur', apres: $d + ['id' => $id]);

        return back()->with('succes', "« {$d['libelle']} » ajouté.");
    }

    public function update(Request $request, int $valeur): RedirectResponse
    {
        $ligne = DB::table('liste_valeur')->where('id', $valeur)->first() ?? abort(404);
        $d = $request->validate([
            'libelle' => ['required', 'string', 'max:255', Rule::unique('liste_valeur')->where('liste', $ligne->liste)->ignore($valeur)],
            'actif' => ['required', 'boolean'],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        DB::table('liste_valeur')->where('id', $valeur)->update($d + ['updated_at' => now()]);
        Audit::enregistrer('modifier', 'liste_valeur', (array) $ligne, $d + ['id' => $valeur]);

        return back()->with('succes', 'Valeur enregistrée.');
    }

    public function destroy(int $valeur): RedirectResponse
    {
        $ligne = DB::table('liste_valeur')->where('id', $valeur)->first() ?? abort(404);
        DB::table('liste_valeur')->where('id', $valeur)->delete();
        Audit::enregistrer('supprimer', 'liste_valeur', (array) $ligne);

        return back()->with('succes', "« {$ligne->libelle} » supprimé.");
    }

    /** Valeurs actives par liste, partagées avec toutes les pages (suggestions des champs). */
    public static function partagees(): array
    {
        return DB::table('liste_valeur')->where('actif', true)->orderBy('ordre')->orderBy('libelle')->get(['liste', 'libelle'])
            ->groupBy('liste')->map(fn ($v) => $v->pluck('libelle'))->all();
    }
}
