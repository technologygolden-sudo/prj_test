<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Emplacements;
use App\Support\Perimetre;
use App\Support\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Kits assemblés : liste, fiche et désassemblage motivé (EF13, RG25, processus 4.3). */
class KitAssembleController extends Controller
{
    public function index(Request $request): Response
    {
        $filtres = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'statut' => ['nullable', 'in:assemble,desassemble,expedie,en_transit'],
        ]);

        return Inertia::render('Stock/Kits/Index', [
            'imprimantes' => ReceptionController::imprimantes(),
            'kits' => $this->requete()->tap(fn ($q) => Perimetre::filtrer($q, 'site.id'))
                ->when($filtres['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w
                    ->where('kit_assemble.uid', strtoupper($t))->orWhere('kit_assemble.numero_serie', 'ilike', "%{$t}%")
                    ->orWhere('type_kit.reference', 'ilike', "%{$t}%")))
                ->when($filtres['statut'] ?? null, fn ($q, $s) => $q->where('kit_assemble.statut', $s))
                ->orderByDesc('kit_assemble.id')->paginate(30)->withQueryString(),
            'filtres' => $filtres,
        ]);
    }

    public function show(int $kit): Response
    {
        abort_unless(Perimetre::couvre('kit_assemble', $kit), 403, 'Ce kit n’est pas dans le stock de votre site.');
        $k = $this->requete()->where('kit_assemble.id', $kit)->first() ?? abort(404);

        return Inertia::render('Stock/Kits/Fiche', [
            'kit' => $k,
            'emplacement' => EmplacementController::libelle($k->emplacement_id),
            'emplacements' => Emplacements::liste(),
            'historique' => EmplacementController::historique('kit_id', $kit),
            'contenu' => DB::table('contenu_kit')->join('unite_article', 'unite_article.id', '=', 'contenu_kit.unite_id')
                ->join('article', 'article.id', '=', 'unite_article.article_id')
                ->where('kit_id', $kit)->orderBy('article.reference')
                ->get(['unite_article.id', 'unite_article.uid', 'unite_article.numero_serie', 'unite_article.nature', 'contenu_kit.quantite', 'article.reference', 'article.nom']),
            'impressions' => UniteController::impressions('kit_id', $kit),
            'imprimantes' => ReceptionController::imprimantes(),
        ]);
    }

    public function desassembler(Request $request, int $kit): RedirectResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']])['motif'];
        Perimetre::exiger('kit_assemble', $kit);

        DB::transaction(function () use ($request, $kit, $motif) {
            $k = DB::table('kit_assemble')->where('id', $kit)->lockForUpdate()->first() ?? abort(404);
            if ($k->statut !== 'assemble') {
                throw ValidationException::withMessages(['motif' => 'Seul un kit « Assemblé » peut être désassemblé (un kit expédié ne se désassemble pas).']);
            }
            $userId = $request->user()->id;

            DB::table('kit_assemble')->where('id', $kit)->update([
                'statut' => 'desassemble', 'desassemble_le' => now(), 'motif_desassemblage' => $motif, 'emplacement_id' => null, 'updated_at' => now(),
            ]);

            foreach (DB::table('contenu_kit')->where('kit_id', $kit)->get() as $ligne) {
                $u = Stock::verrouillerUnite($ligne->unite_id);
                // Les composants reviennent en stock ; un lot épuisé redevient « En stock ».
                $retour = $u->nature === 'serie' ? ['statut' => 'en_stock'] : ($u->statut === 'epuise' ? ['statut' => 'en_stock'] : []);
                Stock::mouvementUnite($u, 'restitution', (int) $ligne->quantite, $userId, "Désassemblage du kit {$kit} : {$motif}", $retour);
                if ($u->nature === 'serie') {
                    // Le composant reprend l'emplacement du kit (et son état si le kit est défectueux).
                    DB::table('unite_article')->where('id', $u->id)->update([
                        'emplacement_id' => $k->emplacement_id, 'proprietaire' => $k->proprietaire,
                        'etat' => $k->etat === 'defectueux' ? 'defectueux' : $u->etat,
                    ]);
                    Emplacements::tracer('affectation', 'unite_id', $u->id, null, $k->emplacement_id, $userId, "Désassemblage du kit {$kit}");
                }
            }

            Audit::enregistrer('desassembler', 'kit_assemble', ['id' => $kit, 'statut' => 'assemble'], ['id' => $kit, 'statut' => 'desassemble', 'motif' => $motif]);
        });

        return back()->with('succes', 'Kit désassemblé : ses composants sont de nouveau en stock.');
    }

    private function requete()
    {
        return DB::table('kit_assemble')
            ->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
            ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->leftJoin('users', 'users.id', '=', 'kit_assemble.utilisateur_id')
            ->leftJoin('emplacement', 'emplacement.id', '=', 'kit_assemble.emplacement_id')->leftJoin('site', 'site.id', '=', 'emplacement.site_id')
            ->select('kit_assemble.*', 'type_kit.reference', 'type_kit.nom', 'type_kit.partno', 'version_kit.numero as version', 'users.name as assembleur',
                DB::raw("concat_ws(' › ', site.nom, emplacement.nom) as emplacement_libelle"));
    }
}
