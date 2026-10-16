<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Emplacements;
use App\Support\Perimetre;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Déplacements internes, cessions GREENO/TEVIA, défauts (SAV) et réparations (Lab). */
class EmplacementController extends Controller
{
    private function cible(Request $request, array $regles = []): array
    {
        $donnees = $request->validate([
            'type' => ['required', 'in:unite,kit'],
            'id' => ['required', 'integer'],
        ] + $regles);
        Perimetre::exiger($donnees['type'] === 'kit' ? 'kit_assemble' : 'unite_article', (int) $donnees['id']); // RG33

        return $donnees;
    }

    public function deplacer(Request $request): RedirectResponse
    {
        $d = $this->cible($request, [
            'emplacement_id' => ['required', 'integer'],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
            'quantite' => ['nullable', 'integer', 'min:1'],
        ]);
        $id = DB::transaction(function () use ($request, $d) {
            $id = Emplacements::deplacer($d['type'], (int) $d['id'], (int) $d['emplacement_id'], $request->user()->id, $d['motif'], isset($d['quantite']) ? (int) $d['quantite'] : null);
            Audit::enregistrer('deplacer', $d['type'] === 'kit' ? 'kit_assemble' : 'unite_article', apres: $d + ['element_deplace' => $id]);

            return $id;
        });

        if ($d['type'] === 'unite' && $id !== (int) $d['id']) {
            return redirect()->route('stock.show', $id)->with('succes', 'Lot scindé et déplacé : imprimez l’étiquette du nouveau lot.');
        }

        return back()->with('succes', 'Déplacement enregistré.');
    }

    public function ceder(Request $request): RedirectResponse
    {
        $d = $this->cible($request, [
            'emplacement_id' => ['required', 'integer'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        DB::transaction(function () use ($request, $d) {
            Emplacements::ceder($d['type'], (int) $d['id'], (int) $d['emplacement_id'], $request->user()->id, $d['motif']);
            Audit::enregistrer('ceder', $d['type'] === 'kit' ? 'kit_assemble' : 'unite_article', apres: $d);
        });

        return back()->with('succes', 'Cession enregistrée.');
    }

    public function defaut(Request $request): RedirectResponse
    {
        $d = $this->cible($request, [
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'emplacement_id' => ['nullable', 'integer'],
        ]);
        DB::transaction(function () use ($request, $d) {
            Emplacements::declarerDefaut($d['type'], (int) $d['id'], $request->user()->id, $d['description'], isset($d['emplacement_id']) ? (int) $d['emplacement_id'] : null);
            Audit::enregistrer('declarer_defaut', $d['type'] === 'kit' ? 'kit_assemble' : 'unite_article', apres: $d);
        });

        return back()->with('succes', 'Défaut déclaré : l’élément ne peut plus aller en shop avant réparation.');
    }

    public function reparation(Request $request): RedirectResponse
    {
        $d = $this->cible($request, [
            'nature' => ['required', 'in:reparation,reconditionnement'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $d) {
            Emplacements::declarerReparation($d['type'], (int) $d['id'], $request->user()->id, $d['nature'], $d['description']);
            Audit::enregistrer('declarer_'.$d['nature'], $d['type'] === 'kit' ? 'kit_assemble' : 'unite_article', apres: $d);
        });

        return back()->with('succes', $d['nature'] === 'reparation' ? 'Réparation enregistrée : l’élément est de nouveau en bon état.' : 'Reconditionnement enregistré.');
    }

    /** Historique d'emplacement et déclarations d'un élément, pour les fiches. */
    public static function historique(string $colonne, int $id): array
    {
        return [
            'deplacements' => DB::table('mouvement_emplacement as m')
                ->leftJoin('emplacement as de', 'de.id', '=', 'm.de_emplacement_id')->leftJoin('site as sd', 'sd.id', '=', 'de.site_id')
                ->leftJoin('emplacement as vers', 'vers.id', '=', 'm.vers_emplacement_id')->leftJoin('site as sv', 'sv.id', '=', 'vers.site_id')
                ->leftJoin('users', 'users.id', '=', 'm.utilisateur_id')
                ->where("m.{$colonne}", $id)->orderByDesc('m.id')
                ->get(['m.id', 'm.horodatage', 'm.type', 'm.motif', 'm.transfert_id', 'users.name as utilisateur',
                    DB::raw("concat_ws(' › ', sd.nom, de.nom) as de"), DB::raw("concat_ws(' › ', sv.nom, vers.nom) as vers")]),
            'declarations' => DB::table('declaration_etat')->leftJoin('users', 'users.id', '=', 'declaration_etat.utilisateur_id')
                ->where($colonne, $id)->orderByDesc('declaration_etat.id')
                ->get(['declaration_etat.id', 'horodatage', 'type', 'description', 'users.name as utilisateur']),
        ];
    }

    /** Emplacement courant lisible. */
    public static function libelle(?int $emplacementId): ?array
    {
        if (! $emplacementId) {
            return null;
        }
        $e = DB::table('emplacement')->join('site', 'site.id', '=', 'emplacement.site_id')->where('emplacement.id', $emplacementId)
            ->first(['emplacement.id', 'emplacement.nom', 'emplacement.type', 'emplacement.vente', 'emplacement.espace', 'site.id as site_id', 'site.nom as site']);

        return $e ? (array) $e + ['libelle' => "{$e->site} › {$e->nom}"] : null;
    }
}
