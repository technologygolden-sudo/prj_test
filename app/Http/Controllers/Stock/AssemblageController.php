<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\TypeKit;
use App\Support\Audit;
use App\Support\Stock;
use App\Support\Uid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Assemblage guidé par scan (EF05, EF06, RG06, RG09, RG11, processus 4.2) :
 * chaque scan est contrôlé aussitôt, puis la validation reverrouille et revérifie tout dans une transaction.
 */
class AssemblageController extends Controller
{
    public function create(Request $request): Response
    {
        $versionId = $request->integer('version') ?: null;

        $types = TypeKit::with('versionActive')->orderBy('reference')->get()
            ->filter(fn (TypeKit $t) => $t->versionActive !== null)
            ->map(fn (TypeKit $t) => ['version_id' => $t->versionActive->id, 'libelle' => "{$t->reference} · {$t->nom} (v{$t->versionActive->numero})"])
            ->values();

        return Inertia::render('Stock/Assemblage', [
            'types' => $types,
            'nomenclature' => $versionId ? $this->nomenclature($versionId) : null,
            'imprimantes' => ReceptionController::imprimantes(),
        ]);
    }

    /** Contrôle d'un scan pendant l'assemblage (JSON). */
    public function verifier(Request $request)
    {
        $donnees = $request->validate([
            'version_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:64'],
        ]);
        $nomenclature = $this->nomenclature($donnees['version_id']);
        $articles = array_column($nomenclature['lignes'], 'article_id');

        $unites = Stock::trouverUnites($donnees['code'], $articles);
        if (count($unites) > 1) {
            return response()->json(['message' => 'Plusieurs unités portent ce n° : scannez le QR code (UID).'], 422);
        }
        $u = $unites[0] ?? null;
        if (! $u) {
            return response()->json(['message' => 'Code inconnu.'], 422);
        }
        if (! in_array($u->article_id, $articles, true)) {
            return response()->json(['message' => 'Cet article ne fait pas partie de la nomenclature.'], 422);
        }
        if ($u->statut !== 'en_stock') {
            return response()->json(['message' => "Cette unité n'est pas en stock (statut : {$u->statut})."], 422);
        }

        return response()->json([
            'unite_id' => $u->id, 'uid' => $u->uid, 'article_id' => $u->article_id, 'nature' => $u->nature,
            'numero_serie' => $u->numero_serie, 'disponible' => $u->quantite_restante,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'version_id' => ['required', 'integer'],
            'composants' => ['required', 'array', 'min:1', 'max:1000'],
            'composants.*.unite_id' => ['required', 'integer', 'distinct'],
            'composants.*.quantite' => ['required', 'integer', 'min:1'],
        ]);

        $kitId = DB::transaction(function () use ($request, $donnees) {
            $userId = $request->user()->id;
            $version = DB::table('version_kit')->where('id', $donnees['version_id'])->first();
            $active = $version ? TypeKit::find($version->type_kit_id)?->versionActive : null;
            if (! $version || ! $active || $active->id !== $version->id) {
                throw ValidationException::withMessages(['version_id' => 'Ce kit n’a plus cette version active : rechargez la page.']);
            }
            $attendu = DB::table('composition')->where('version_kit_id', $version->id)->pluck('quantite', 'article_id')->map(fn ($q) => (int) $q)->all();

            // Verrouillage des unités puis contrôle complet (RG11).
            $unites = DB::table('unite_article')->whereIn('id', array_column($donnees['composants'], 'unite_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $recu = [];
            $porteur = null;
            foreach ($donnees['composants'] as $c) {
                $u = $unites[$c['unite_id']] ?? null;
                if (! $u || $u->statut !== 'en_stock') {
                    throw ValidationException::withMessages(['composants' => 'Un composant n’est plus en stock : rescannez-le.']);
                }
                if (! isset($attendu[$u->article_id])) {
                    throw ValidationException::withMessages(['composants' => "L'unité {$u->uid} n'appartient pas à la nomenclature."]);
                }
                $quantite = $u->nature === 'serie' ? 1 : (int) $c['quantite'];
                if ($quantite > $u->quantite_restante) {
                    throw ValidationException::withMessages(['composants' => "Le lot {$u->uid} ne contient plus que {$u->quantite_restante}."]);
                }
                $recu[$u->article_id] = ($recu[$u->article_id] ?? 0) + $quantite;
                if ($u->article_id === $version->article_porteur_id) {
                    $porteur = $u;
                }
            }
            ksort($attendu);
            ksort($recu);
            if ($recu !== $attendu || ! $porteur) {
                throw ValidationException::withMessages(['composants' => 'Les composants scannés ne correspondent pas exactement à la nomenclature.']);
            }

            $kitId = DB::table('kit_assemble')->insertGetId([
                'uid' => Uid::generer(Uid::KIT), 'numero_serie' => '', 'statut' => 'assemble', 'assemble_le' => now(),
                'version_kit_id' => $version->id, 'unite_porteur_id' => $porteur->id, 'utilisateur_id' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($donnees['composants'] as $c) {
                $u = $unites[$c['unite_id']];
                $quantite = $u->nature === 'serie' ? 1 : (int) $c['quantite'];
                DB::table('contenu_kit')->insert(['kit_id' => $kitId, 'unite_id' => $u->id, 'quantite' => $quantite]);
                // RG09 : un article sérialisé passe « Assemblé » ; un lot vidé passe « Épuisé ».
                Stock::mouvementUnite($u, 'consommation', -$quantite, $userId, "Assemblage du kit {$kitId}", $u->nature === 'serie' ? ['statut' => 'assemble'] : []);
            }

            Audit::enregistrer('assembler', 'kit_assemble', apres: ['id' => $kitId, 'version_kit_id' => $version->id, 'composants' => $donnees['composants']]);

            return $kitId;
        });

        return redirect()->route('kits-assembles.show', $kitId)->with('succes', 'Kit assemblé. Imprimez son étiquette.');
    }

    /** @return array{version_id: int, porteur_id: int, lignes: list<array<string, mixed>>} */
    private function nomenclature(int $versionId): array
    {
        $version = DB::table('version_kit')->where('id', $versionId)->where('verrouillee', true)->first() ?? abort(404);

        return [
            'version_id' => $version->id,
            'porteur_id' => $version->article_porteur_id,
            'lignes' => DB::table('composition')->join('article', 'article.id', '=', 'composition.article_id')
                ->where('version_kit_id', $version->id)->orderByRaw('article.id = ? desc', [$version->article_porteur_id])->orderBy('article.reference')
                ->get(['article.id as article_id', 'article.reference', 'article.nom', 'article.est_serialise', 'composition.quantite'])
                ->map(fn ($l) => (array) $l)->all(),
        ];
    }
}
