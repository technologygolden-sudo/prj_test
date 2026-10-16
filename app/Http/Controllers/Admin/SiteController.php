<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Database\Seeders\SiteSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Sites et emplacements (Paramètres) : ajout de centres de service, renommage, activation. */
class SiteController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['ville' => ['required', 'string', 'max:100']]);
        $code = substr('CS-'.Str::upper(Str::slug(Str::ascii($d['ville']), '')), 0, 32);
        if (DB::table('site')->where('code', $code)->exists()) {
            return back()->with('alerte', "Un centre de service existe déjà pour {$d['ville']}.");
        }

        DB::transaction(function () use ($d, $code) {
            $site = DB::table('site')->insertGetId(['code' => $code, 'nom' => "Centre de service {$d['ville']}", 'type' => 'centre_service', 'ville' => $d['ville'], 'created_at' => now(), 'updated_at' => now()]);
            foreach (SiteSeeder::EMPLACEMENTS_CENTRE as $type => [$nom, $vente]) {
                DB::table('emplacement')->insert(['site_id' => $site, 'type' => $type, 'nom' => $nom, 'vente' => $vente, 'created_at' => now(), 'updated_at' => now()]);
            }
            Audit::enregistrer('creer', 'site', apres: ['id' => $site, 'code' => $code, 'ville' => $d['ville']]);
        });

        return back()->with('succes', "Centre de service {$d['ville']} créé avec ses trois emplacements.");
    }

    public function update(Request $request, int $site): RedirectResponse
    {
        $d = $request->validate(['nom' => ['required', 'string', 'max:255'], 'actif' => ['required', 'boolean']]);
        $avant = (array) (DB::table('site')->where('id', $site)->first() ?? abort(404));
        if (! $d['actif'] && $this->stockPresent('site_id', $site)) {
            return back()->with('alerte', 'Ce site contient du stock : videz-le avant de le désactiver.');
        }
        DB::table('site')->where('id', $site)->update($d + ['updated_at' => now()]);
        Audit::enregistrer('modifier', 'site', $avant, $d + ['id' => $site]);

        return back()->with('succes', 'Site enregistré.');
    }

    public function emplacement(Request $request, int $emplacement): RedirectResponse
    {
        $d = $request->validate(['nom' => ['required', 'string', 'max:255'], 'actif' => ['required', 'boolean']]);
        $avant = (array) (DB::table('emplacement')->where('id', $emplacement)->first() ?? abort(404));
        if (! $d['actif'] && $this->stockPresent('id', $emplacement)) {
            return back()->with('alerte', 'Cet emplacement contient du stock : déplacez-le avant de le désactiver.');
        }
        DB::table('emplacement')->where('id', $emplacement)->update($d + ['updated_at' => now()]);
        Audit::enregistrer('modifier', 'emplacement', $avant, $d + ['id' => $emplacement]);

        return back()->with('succes', 'Emplacement enregistré.');
    }

    private function stockPresent(string $colonne, int $valeur): bool
    {
        $emplacements = DB::table('emplacement')->where($colonne, $valeur)->pluck('id');

        return DB::table('unite_article')->whereIn('emplacement_id', $emplacements)->exists()
            || DB::table('kit_assemble')->whereIn('emplacement_id', $emplacements)->exists();
    }
}
