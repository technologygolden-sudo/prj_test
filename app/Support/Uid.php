<?php

namespace App\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * UID des unités physiques (dossier v2, section 6.2) :
 * préfixe de type (A article, L lot, K kit) + ULID complet + caractère de contrôle ISO 7064 Mod 37,36.
 * Exemple : A-01JA5K8W3QX7Z2M4N6P8R0T2V4-7 (30 caractères).
 */
class Uid
{
    public const ARTICLE = 'A';

    public const LOT = 'L';

    public const KIT = 'K';

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function generer(string $prefixe): string
    {
        if (! in_array($prefixe, [self::ARTICLE, self::LOT, self::KIT], true)) {
            throw new InvalidArgumentException("Préfixe d'UID inconnu : {$prefixe}");
        }
        $ulid = strtoupper((string) Str::ulid());

        return "{$prefixe}-{$ulid}-".self::controle($prefixe.$ulid);
    }

    public static function estValide(string $uid): bool
    {
        if (! preg_match('/^([ALK])-([0-9A-HJKMNP-TV-Z]{26})-([0-9A-Z])$/', $uid, $m)) {
            return false;
        }

        return self::controle($m[1].$m[2]) === $m[3];
    }

    /** ISO 7064 Mod 37,36 (système hybride, M = 36). */
    public static function controle(string $donnees): string
    {
        $m = 36;
        $p = $m;
        foreach (str_split(strtoupper($donnees)) as $caractere) {
            $valeur = strpos(self::ALPHABET, $caractere);
            if ($valeur === false) {
                throw new InvalidArgumentException("Caractère non autorisé : {$caractere}");
            }
            $s = ($p + $valeur) % $m;
            $p = (($s === 0 ? $m : $s) * 2) % ($m + 1);
        }

        return self::ALPHABET[($m + 1 - $p) % $m];
    }
}
