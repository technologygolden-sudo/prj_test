<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Models\TypeKit;
use App\Models\VersionKit;
use App\Support\Audit;
use App\Support\Nomenclature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Versions de nomenclature (RG10) : une seule version en brouillon à la fois ;
 * une version verrouillée est figée, toute évolution crée la version suivante.
 */
class VersionKitController extends Controller
{
    /** Crée la version suivante en recopiant la plus récente. */
    public function store(TypeKit $kit): RedirectResponse
    {
        if ($kit->brouillon()->exists()) {
            return back()->with('alerte', 'Une version est déjà en brouillon : terminez-la avant d’en créer une autre.');
        }

        $source = $kit->versions()->with('articles')->first();

        $version = DB::transaction(function () use ($kit, $source) {
            $version = $kit->versions()->create([
                'numero' => $source->numero + 1,
                'article_porteur_id' => $source->article_porteur_id,
            ]);
            $lignes = $source->articles->map(fn ($a) => ['article_id' => $a->id, 'quantite' => $a->pivot->quantite])->all();
            Nomenclature::enregistrer($version, $source->article_porteur_id, $lignes);
            Audit::enregistrer('creer', $version, apres: Nomenclature::instantane($version));

            return $version;
        });

        return back()->with('succes', "Version {$version->numero} créée en brouillon à partir de la version {$source->numero}.");
    }

    public function update(Request $request, VersionKit $version): RedirectResponse
    {
        if ($version->verrouillee) {
            return back()->with('alerte', 'Cette version est verrouillée : créez une nouvelle version pour la faire évoluer.');
        }

        $donnees = $request->validate(Nomenclature::regles());
        $avant = Nomenclature::instantane($version);

        DB::transaction(function () use ($version, $donnees, $avant) {
            Nomenclature::enregistrer($version, isset($donnees['porteur_id']) ? (int) $donnees['porteur_id'] : null, $donnees['lignes'] ?? []);
            Audit::enregistrer('modifier', $version, $avant, Nomenclature::instantane($version));
        });

        return back()->with('succes', "Nomenclature de la version {$version->numero} enregistrée.");
    }

    public function verrouiller(Request $request, VersionKit $version): RedirectResponse
    {
        if ($version->verrouillee) {
            return back()->with('alerte', 'Cette version est déjà verrouillée.');
        }
        if ($version->article_porteur_id === null) {
            return back()->with('alerte', 'Choisissez d’abord l’article porteur de série (RG05), puis enregistrez la nomenclature.');
        }

        DB::transaction(function () use ($request, $version) {
            $version->forceFill([
                'verrouillee' => true,
                'verrouillee_le' => now(),
                'verrouillee_par' => $request->user()->id,
            ])->save();
            Audit::enregistrer('verrouiller', $version, apres: Nomenclature::instantane($version));
        });

        return back()->with('succes', "Version {$version->numero} verrouillée : elle devient la version active.");
    }

    public function destroy(VersionKit $version): RedirectResponse
    {
        if ($version->verrouillee) {
            return back()->with('alerte', 'Une version verrouillée ne peut pas être supprimée.');
        }
        if ($version->typeKit->versions()->count() === 1) {
            return back()->with('alerte', 'C’est la seule version de ce kit : supprimez plutôt le type de kit.');
        }

        DB::transaction(function () use ($version) {
            Audit::enregistrer('supprimer', $version, Nomenclature::instantane($version));
            DB::table('composition')->where('version_kit_id', $version->id)->delete();
            $version->delete();
        });

        return back()->with('succes', "Brouillon de la version {$version->numero} supprimé.");
    }
}
