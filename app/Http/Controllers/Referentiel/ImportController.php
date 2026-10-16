<?php

namespace App\Http\Controllers\Referentiel;

use App\Http\Controllers\Controller;
use App\Support\Import\ImportReferentiel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Import Excel du référentiel en deux temps : aperçu des changements, puis confirmation (EF01). */
class ImportController extends Controller
{
    private const DOSSIER = 'imports';

    public function index(Request $request): Response
    {
        return Inertia::render('Referentiel/Import', [
            'apercu' => $request->session()->get('import.apercu'),
            'bilan' => $request->session()->get('import.bilan'),
        ]);
    }

    public function analyser(Request $request, ImportReferentiel $import): RedirectResponse
    {
        $request->validate([
            'fichier' => ['required', 'file', 'max:10240', 'mimes:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
        ], ['fichier.mimes' => 'Le fichier doit être un classeur Excel .xlsx.']);

        $this->nettoyer($request);
        $jeton = (string) Str::uuid();
        $request->file('fichier')->storeAs(self::DOSSIER, "{$jeton}.xlsx", 'local');
        $donnees = $import->lire(Storage::disk('local')->path(self::DOSSIER."/{$jeton}.xlsx"));

        $apercu = [
            'jeton' => $jeton,
            'fichier' => $request->file('fichier')->getClientOriginalName(),
            'erreurs' => $donnees['erreurs'],
            'lignes' => ['articles' => count($donnees['articles']), 'kits' => count($donnees['kits'])],
        ] + ($donnees['erreurs'] ? [] : $import->analyser($donnees));

        $request->session()->put('import.apercu', $apercu);
        $request->session()->forget('import.bilan');

        return redirect()->route('import.index');
    }

    public function appliquer(Request $request, ImportReferentiel $import): RedirectResponse
    {
        $jeton = $request->validate(['jeton' => ['required', 'uuid']])['jeton'];
        $apercu = $request->session()->get('import.apercu');
        $chemin = self::DOSSIER."/{$jeton}.xlsx";

        // Le jeton doit être celui de l'aperçu de cet utilisateur : on n'applique que ce qui a été montré.
        if (! $apercu || $apercu['jeton'] !== $jeton || ! Storage::disk('local')->exists($chemin)) {
            return redirect()->route('import.index')->with('alerte', 'Aperçu expiré : chargez à nouveau le fichier.');
        }

        $donnees = $import->lire(Storage::disk('local')->path($chemin));
        if ($donnees['erreurs']) {
            return redirect()->route('import.index')->with('alerte', 'Le fichier contient des erreurs : corrigez-les avant d’importer.');
        }

        $bilan = $import->appliquer($donnees, $request->user()->id, $apercu['fichier']);
        $this->nettoyer($request);
        $request->session()->put('import.bilan', $bilan + ['fichier' => $apercu['fichier']]);

        return redirect()->route('import.index')->with('succes', 'Import terminé.');
    }

    public function annuler(Request $request): RedirectResponse
    {
        $this->nettoyer($request);
        $request->session()->forget('import.bilan');

        return redirect()->route('import.index');
    }

    private function nettoyer(Request $request): void
    {
        if ($jeton = $request->session()->pull('import.apercu')['jeton'] ?? null) {
            Storage::disk('local')->delete(self::DOSSIER."/{$jeton}.xlsx");
        }
    }
}
