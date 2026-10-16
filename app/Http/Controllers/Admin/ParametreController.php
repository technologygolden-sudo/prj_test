<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres société (RG15), imprimantes autorisées et modèles d'étiquette.
 * Les modèles ne contiennent jamais de ZPL saisi par l'utilisateur (risque d'injection) :
 * seuls les dimensions, la résolution et l'activation sont réglables.
 */
class ParametreController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Parametres', [
            'societe' => DB::table('parametre_societe')->where('id', 1)->first(['nom_societe', 'num_sav', 'modifie_le']),
            'imprimantes' => DB::table('imprimante')->orderBy('nom')->get(),
            'modeles' => DB::table('modele_etiquette')->orderBy('cible')->orderBy('format')->get(['id', 'code', 'cible', 'format', 'largeur_mm', 'hauteur_mm', 'dpi', 'actif']),
        ]);
    }

    public function societe(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom_societe' => ['required', 'string', 'max:64'],
            'num_sav' => ['required', 'string', 'max:32', 'regex:/^[0-9 +().-]+$/'],
        ], ['num_sav.regex' => 'Le n° SAV ne contient que des chiffres, espaces et + ( ) . -']);

        $avant = (array) DB::table('parametre_societe')->where('id', 1)->first();
        DB::table('parametre_societe')->updateOrInsert(['id' => 1], $donnees + ['modifie_par' => $request->user()->id, 'modifie_le' => now()]);
        Audit::enregistrer('modifier', 'parametre_societe', $avant, $donnees);

        return back()->with('succes', 'Paramètres société enregistrés : ils s’appliquent aux prochaines étiquettes.');
    }

    public function enregistrerImprimante(Request $request, ?int $imprimante = null): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:255', Rule::unique('imprimante', 'nom')->ignore($imprimante)],
            'adresse' => ['required', 'string', 'max:255', 'regex:#^(tcp://[0-9a-zA-Z.\-]+:\d{2,5}|fichier:[a-z0-9_-]+|navigateur)$#i'],
            'modele' => ['required', 'string', 'max:64'],
            'dpi' => ['required', 'in:203,300,600'],
            'actif' => ['required', 'boolean'],
        ], ['adresse.regex' => 'Adresse attendue : tcp://IP:9100, fichier:nom ou navigateur.']);

        if ($imprimante) {
            $avant = (array) DB::table('imprimante')->where('id', $imprimante)->first();
            DB::table('imprimante')->where('id', $imprimante)->update($donnees + ['updated_at' => now()]);
            Audit::enregistrer('modifier', 'imprimante', $avant, $donnees + ['id' => $imprimante]);
        } else {
            $id = DB::table('imprimante')->insertGetId($donnees + ['created_at' => now(), 'updated_at' => now()]);
            Audit::enregistrer('creer', 'imprimante', apres: $donnees + ['id' => $id]);
        }

        return back()->with('succes', 'Imprimante enregistrée.');
    }

    public function modele(Request $request, int $modele): RedirectResponse
    {
        $donnees = $request->validate([
            'largeur_mm' => ['required', 'numeric', 'between:10,120'],
            'hauteur_mm' => ['required', 'numeric', 'between:10,120'],
            'dpi' => ['required', 'in:203,300,600'],
            'actif' => ['required', 'boolean'],
        ]);
        $avant = (array) DB::table('modele_etiquette')->where('id', $modele)->first() ?: abort(404);
        DB::table('modele_etiquette')->where('id', $modele)->update($donnees + ['updated_at' => now()]);
        Audit::enregistrer('modifier', 'modele_etiquette', $avant, $donnees + ['id' => $modele]);

        return back()->with('succes', 'Modèle d’étiquette enregistré.');
    }
}
