<?php

namespace App\Support\Etiquettes;

/**
 * Gabarit ZPL 50 × 30 mm pour Zebra ZD411 (dossier v2, section 6.3).
 * Coordonnées écrites à 203 dpi et mises à l'échelle pour 300 dpi.
 * Chaque champ passe par ^FH : tout caractère hors lettres, chiffres et espace est encodé
 * en hexadécimal (_XX), donc aucune commande ZPL ne peut être injectée.
 */
class GenerateurZpl
{
    public function generer(ContenuEtiquette $c, int $dpi = 203, int $copies = 1): string
    {
        $e = fn (int $points) => (int) round($points * $dpi / 203);
        $loupeQr = $dpi >= 300 ? 4 : 3;
        $module = $dpi >= 300 ? 2 : 1;
        $policeLibelle = mb_strlen($c->libelle) > 18 ? 24 : 29;
        $largeurCode = $this->largeurCode128($c->codeBarres) * $module;
        $zoneCode = $e(285);
        $xCode = $e(20) + max(0, intdiv($zoneCode - $largeurCode, 2));

        $lignes = [
            '^XA',
            '^CI28',
            '^PW'.$e(400),
            '^LL'.$e(240),
            '^LH0,0',
            sprintf('^FO%d,%d^A0N,%d,%d^FH_^FD%s^FS', $e(20), $e(20), $e(22), $e(22), $this->champ($c->societe)),
            sprintf('^FO%d,%d^A0N,%d,%d^FB%d,1,0,R^FH_^FD%s^FS', $e(20), $e(25), $e(17), $e(16), $e(360), $this->champ($c->sav)),
            sprintf('^FO%d,%d^GB%d,1,1,B,0^FS', $e(20), $e(46), $e(360)),
            sprintf('^FO%d,%d^A0N,%d,%d^FB%d,2,0,L^FH_^FD%s^FS', $e(20), $e(54), $e($policeLibelle), $e($policeLibelle), $e(277), $this->champ($c->libelle)),
            sprintf('^FO%d,%d^A0N,%d,%d^FH_^FD%s^FS', $e(20), $e(100), $e(22), $e(19), $this->champ($c->reference)),
            sprintf('^FO%d,%d^BQN,2,%d^FH_^FDMA,%s^FS', $e(305), $e(54), $loupeQr, $this->champ($c->qr)),
            sprintf('^FO%d,%d^BY%d,3,%d^BCN,%d,N,N,N,A^FH_^FD%s^FS', $xCode, $e(134), $module, $e(60), $e(60), $this->champ($c->codeBarres)),
            sprintf('^FO%d,%d^A0N,%d,%d^FB%d,1,0,C^FH_^FD%s^FS', $e(20), $e(200), $e(24), $e(22), $e(360), $this->champ($c->pied)),
            '^PQ'.max(1, min(100, $copies)),
            '^XZ',
        ];

        return implode("\n", $lignes)."\n";
    }

    /** Encode tout caractère hors [A-Za-z0-9 ] en _XX (octets UTF-8). */
    public function champ(string $valeur): string
    {
        $valeur = preg_replace('/[\x00-\x1F\x7F]/u', '', $valeur) ?? '';

        return preg_replace_callback(
            '/[^A-Za-z0-9 ]/u',
            fn ($m) => implode('', array_map(fn ($octet) => sprintf('_%02X', ord($octet)), str_split($m[0]))),
            $valeur,
        );
    }

    /** Largeur estimée en modules (jeu B), pour centrer le code-barres. */
    private function largeurCode128(string $texte): int
    {
        return (strlen($texte) + 3) * 11 + 2;
    }
}
