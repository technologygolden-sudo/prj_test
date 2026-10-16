<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Sorties de stock : expédition, rebut, retour fournisseur (EF14, processus 4.4). */
class SortieController extends Controller
{
    private const STATUT_APRES = ['expedition' => 'expedie', 'rebut' => 'rebute', 'retour_fournisseur' => 'retourne'];

    public function index(): Response
    {
        return Inertia::render('Stock/Sorties/Index', [
            'sorties' => DB::table('sortie')->leftJoin('users', 'users.id', '=', 'sortie.utilisateur_id')
                ->select('sortie.*', 'users.name as utilisateur')
                ->selectSub(fn ($q) => $q->from('mouvement_stock')->whereColumn('sortie_id', 'sortie.id')->selectRaw('count(*)'), 'lignes')
                ->orderByDesc('sortie.id')->paginate(25),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Stock/Sorties/Creer');
    }

    /** Contrôle d'un code scanné avant de l'ajouter à la sortie (renvoie du JSON). */
    public function verifier(Request $request)
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:64']])['code']);

        $kit = DB::table('kit_assemble')->join('version_kit', 'version_kit.id', '=', 'kit_assemble.version_kit_id')
            ->join('type_kit', 'type_kit.id', '=', 'version_kit.type_kit_id')
            ->where(fn ($q) => $q->where('kit_assemble.uid', strtoupper($code))->orWhere('kit_assemble.numero_serie', $code))
            ->where('kit_assemble.statut', 'assemble')
            ->first(['kit_assemble.id', 'kit_assemble.uid', 'kit_assemble.numero_serie', 'type_kit.reference', 'type_kit.nom']);
        if ($kit) {
            return response()->json(['type' => 'kit', 'id' => $kit->id, 'uid' => $kit->uid, 'libelle' => "Kit {$kit->reference} · S/N {$kit->numero_serie}", 'max' => 1]);
        }

        $unites = array_values(array_filter(Stock::trouverUnites($code), fn ($u) => $u->statut === 'en_stock'));
        if (count($unites) === 1) {
            $u = $unites[0];
            $article = DB::table('article')->where('id', $u->article_id)->first();

            return response()->json([
                'type' => 'unite', 'id' => $u->id, 'uid' => $u->uid, 'max' => $u->quantite_restante,
                'libelle' => $article->reference.' · '.($u->numero_serie ? "S/N {$u->numero_serie}" : "lot de {$u->quantite_restante}"),
            ]);
        }

        return response()->json(['message' => count($unites) > 1
            ? 'Plusieurs unités portent ce n° : scannez le QR code (UID).'
            : 'Aucun kit assemblé ni unité en stock pour ce code.'], 422);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'type' => ['required', 'in:expedition,rebut,retour_fournisseur'],
            'destinataire' => ['nullable', 'required_if:type,expedition', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:64'],
            'lignes' => ['required', 'array', 'min:1', 'max:500'],
            'lignes.*.type' => ['required', 'in:kit,unite'],
            'lignes.*.id' => ['required', 'integer', 'distinct'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
        ]);

        $sortieId = DB::transaction(function () use ($request, $donnees) {
            $userId = $request->user()->id;
            $statut = self::STATUT_APRES[$donnees['type']];
            $sortieId = DB::table('sortie')->insertGetId([
                'date' => now()->toDateString(), 'type' => $donnees['type'], 'destinataire' => $donnees['destinataire'] ?? null,
                'document' => $donnees['document'] ?? null, 'utilisateur_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($donnees['lignes'] as $ligne) {
                if ($ligne['type'] === 'kit') {
                    $kit = DB::table('kit_assemble')->where('id', $ligne['id'])->lockForUpdate()->first();
                    if (! $kit || $kit->statut !== 'assemble') {
                        throw ValidationException::withMessages(['lignes' => 'Un kit de la liste n’est plus « Assemblé ».']);
                    }
                    if ($donnees['type'] !== 'expedition') {
                        throw ValidationException::withMessages(['lignes' => 'Un kit ne peut sortir que par expédition : désassemblez-le pour rebuter ou retourner ses composants.']);
                    }
                    DB::table('mouvement_stock')->insert([
                        'type' => 'sortie', 'quantite' => -1, 'kit_id' => $kit->id, 'sortie_id' => $sortieId, 'utilisateur_id' => $userId,
                    ]);
                    DB::table('kit_assemble')->where('id', $kit->id)->update(['statut' => 'expedie', 'updated_at' => now()]);
                    DB::table('unite_article')->whereIn('id', DB::table('contenu_kit')->where('kit_id', $kit->id)->pluck('unite_id'))
                        ->where('nature', 'serie')->update(['statut' => 'expedie', 'updated_at' => now()]);

                    continue;
                }

                $u = Stock::verrouillerUnite($ligne['id']);
                if (! $u || $u->statut !== 'en_stock') {
                    throw ValidationException::withMessages(['lignes' => 'Une unité de la liste n’est plus en stock.']);
                }
                $quantite = $u->nature === 'serie' ? 1 : (int) $ligne['quantite'];
                $vide = $u->nature === 'serie' || $quantite === (int) $u->quantite_restante;
                Stock::mouvementUnite($u, 'sortie', -$quantite, $userId, null, ['sortie_id' => $sortieId] + ($vide ? ['statut' => $statut] : []));
            }

            Audit::enregistrer('creer', 'sortie', apres: $donnees + ['id' => $sortieId]);

            return $sortieId;
        });

        return redirect()->route('sorties.index')->with('succes', "Sortie n° {$sortieId} enregistrée.");
    }
}
