<?php

namespace App\Support\Etiquettes;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Envoi d'étiquettes et traçabilité des impressions (RG12, EF04, EF06).
 *
 * Adresse d'imprimante :
 *  - « tcp://10.0.0.50:9100 » : ZPL envoyé directement à une Zebra ;
 *  - « fichier:nom »          : ZPL écrit dans storage/app/impressions (poste de développement) ;
 *  - « navigateur »           : PDF renvoyé au navigateur pour une imprimante de bureau.
 */
class Imprimeur
{
    public function __construct(private GenerateurZpl $zpl, private GenerateurPdf $pdf) {}

    /**
     * @param  list<array{type: string, id: int}>  $cibles  type = unite | kit
     * @return array{format: string, pdf: ?string, impressions: int}
     */
    public function imprimer(array $cibles, int $imprimanteId, int $utilisateurId, int $copies = 1, ?string $motif = null): array
    {
        $imprimante = DB::table('imprimante')->where('id', $imprimanteId)->where('actif', true)->first();
        if (! $imprimante) {
            throw ValidationException::withMessages(['imprimante_id' => 'Imprimante inconnue ou désactivée.']);
        }
        $format = $imprimante->adresse === 'navigateur' ? 'PDF' : 'ZPL';

        $contenus = [];
        $lignes = [];
        foreach ($cibles as $cible) {
            $contenu = $cible['type'] === 'kit' ? ContenuEtiquette::pourKit($cible['id']) : ContenuEtiquette::pourUnite($cible['id']);
            $modele = DB::table('modele_etiquette')
                ->where(['cible' => $contenu->cible, 'format' => $format, 'actif' => true])
                ->orderByRaw('dpi = ? desc', [$imprimante->dpi])->first();
            if (! $modele) {
                throw ValidationException::withMessages(['imprimante_id' => "Aucun modèle d'étiquette {$format} actif pour « {$contenu->cible} »."]);
            }

            $colonne = $cible['type'] === 'kit' ? 'kit_id' : 'unite_id';
            $reimpression = DB::table('impression_etiquette')->where($colonne, $cible['id'])->exists();
            // RG12 : toute réimpression exige un motif.
            if ($reimpression && blank($motif)) {
                throw ValidationException::withMessages(['motif' => 'Cette étiquette a déjà été imprimée : indiquez le motif de la réimpression.']);
            }

            $contenus[] = $contenu;
            $lignes[] = [
                $colonne => $cible['id'],
                'copies' => $copies,
                'est_reimpression' => $reimpression,
                'motif' => $reimpression ? $motif : null,
                'modele_id' => $modele->id,
                'imprimante_id' => $imprimante->id,
                'utilisateur_id' => $utilisateurId,
            ];
        }

        $pdf = null;
        if ($format === 'PDF') {
            $pdf = $this->pdf->generer($contenus, $copies);
        } else {
            $zpl = implode('', array_map(fn ($c) => $this->zpl->generer($c, $imprimante->dpi, $copies), $contenus));
            $this->envoyer($imprimante->adresse, $zpl);
        }

        DB::transaction(function () use ($lignes, $imprimante) {
            foreach ($lignes as $ligne) {
                $id = DB::table('impression_etiquette')->insertGetId($ligne);
                Audit::enregistrer($ligne['est_reimpression'] ? 'reimprimer' : 'imprimer', 'impression_etiquette', apres: $ligne + [
                    'id' => $id, 'imprimante' => $imprimante->nom,
                ]);
            }
        });

        return ['format' => $format, 'pdf' => $pdf, 'impressions' => count($lignes)];
    }

    private function envoyer(string $adresse, string $zpl): void
    {
        if (str_starts_with($adresse, 'fichier:')) {
            $nom = preg_replace('/[^a-z0-9_-]/i', '', substr($adresse, 8)) ?: 'imprimante';
            Storage::disk('local')->put("impressions/{$nom}-".now()->format('Ymd-His-u').'.zpl', $zpl);

            return;
        }

        if (! preg_match('#^tcp://([0-9a-zA-Z.\-]+):(\d{2,5})$#', $adresse, $m)) {
            throw new RuntimeException("Adresse d'imprimante invalide : {$adresse}");
        }
        $socket = @fsockopen($m[1], (int) $m[2], $code, $message, 3);
        if (! $socket) {
            throw ValidationException::withMessages(['imprimante_id' => "Imprimante injoignable ({$m[1]}:{$m[2]}) : {$message}"]);
        }
        stream_set_timeout($socket, 3);
        fwrite($socket, $zpl);
        fclose($socket);
    }
}
