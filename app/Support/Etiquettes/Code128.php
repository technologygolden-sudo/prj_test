<?php

namespace App\Support\Etiquettes;

use InvalidArgumentException;

/**
 * Code 128 jeu B (ASCII 32 à 126) rendu en SVG, pour les étiquettes PDF.
 * Les étiquettes ZPL utilisent le Code 128 de l'imprimante (^BC).
 */
class Code128
{
    /** Motifs barre/espace de chaque valeur 0..106 (106 = STOP, sans la barre finale). */
    private const MOTIFS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '233111',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /** @return list<int> largeurs alternées barre/espace en modules, zones de silence comprises */
    public static function modules(string $texte): array
    {
        if ($texte === '' || preg_match('/[^\x20-\x7E]/', $texte)) {
            throw new InvalidArgumentException('Code 128 B : caractères ASCII imprimables uniquement.');
        }

        $valeurs = [self::START_B];
        foreach (str_split($texte) as $caractere) {
            $valeurs[] = ord($caractere) - 32;
        }
        $somme = self::START_B;
        foreach (array_slice($valeurs, 1) as $position => $valeur) {
            $somme += $valeur * ($position + 1);
        }
        $valeurs[] = $somme % 103;
        $valeurs[] = self::STOP;

        $largeurs = [];
        foreach ($valeurs as $valeur) {
            foreach (str_split(self::MOTIFS[$valeur]) as $largeur) {
                $largeurs[] = (int) $largeur;
            }
        }
        $largeurs[] = 2; // barre de fin du STOP

        return $largeurs;
    }

    public static function svg(string $texte, float $hauteur = 40): string
    {
        $x = 10; // zone de silence de 10 modules
        $barres = '';
        foreach (self::modules($texte) as $i => $largeur) {
            if ($i % 2 === 0) {
                $barres .= sprintf('<rect x="%d" y="0" width="%d" height="%s"/>', $x, $largeur, $hauteur);
            }
            $x += $largeur;
        }
        $total = $x + 10;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %s" width="%d" height="%s" preserveAspectRatio="none"><g fill="#000">%s</g></svg>',
            $total, $hauteur, $total, $hauteur, $barres,
        );
    }
}
