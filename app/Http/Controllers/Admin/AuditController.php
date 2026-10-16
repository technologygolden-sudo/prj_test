<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Journal d'audit consultable et exportable (EF12, ENF05). */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filtres = $this->filtres($request);

        return Inertia::render('Admin/Audit', [
            'lignes' => $this->requete($filtres)->paginate(50)->withQueryString(),
            'filtres' => $filtres,
            'utilisateurs' => DB::table('users')->orderBy('login')->get(['id', 'login']),
            'actions' => DB::table('journal_audit')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    public function exporter(Request $request): StreamedResponse
    {
        $requete = $this->requete($this->filtres($request));

        return response()->streamDownload(function () use ($requete) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Horodatage', 'Utilisateur', 'IP', 'Action', 'Objet', 'Identifiant', 'Avant', 'Après'], ';', '"', '');
            foreach ($requete->lazy(500) as $l) {
                fputcsv($sortie, [$l->horodatage, $l->login, $l->ip, $l->action, $l->objet_type, $l->objet_id, $l->valeur_avant, $l->valeur_apres], ';', '"', '');
            }
            fclose($sortie);
        }, 'journal-audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filtres(Request $request): array
    {
        return $request->validate([
            'utilisateur' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:64'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
        ]);
    }

    private function requete(array $f)
    {
        return DB::table('journal_audit')->leftJoin('users', 'users.id', '=', 'journal_audit.utilisateur_id')
            ->when($f['utilisateur'] ?? null, fn ($q, $v) => $q->where('utilisateur_id', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($f['du'] ?? null, fn ($q, $v) => $q->where('horodatage', '>=', $v))
            ->when($f['au'] ?? null, fn ($q, $v) => $q->where('horodatage', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->orderByDesc('journal_audit.id')
            ->select('journal_audit.*', 'users.login');
    }
}
