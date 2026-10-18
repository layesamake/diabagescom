<?php

namespace App\Domain\Audio;

/**
 * Nombres en français, découpés en mots (codes CHF-01 à CHF-30 du
 * catalogue audio). Sert de modèle : la règle de la langue locale choisie
 * par l'administrateur sera fixée plus tard (cahier des charges, lot 4).
 *
 * Exemple : 45 750 → quarante, cinq, mille, sept, cent, cinquante.
 */
final class NumberWords
{
    private const array UNITS = [
        0 => 'CHF-01', 1 => 'CHF-02', 2 => 'CHF-03', 3 => 'CHF-04', 4 => 'CHF-05',
        5 => 'CHF-06', 6 => 'CHF-07', 7 => 'CHF-08', 8 => 'CHF-09', 9 => 'CHF-10',
        10 => 'CHF-11', 11 => 'CHF-12', 12 => 'CHF-13', 13 => 'CHF-14', 14 => 'CHF-15',
        15 => 'CHF-16', 16 => 'CHF-17',
    ];

    private const string ET = 'CHF-24';

    private const string CENT = 'CHF-25';

    private const string MILLE = 'CHF-26';

    private const string MILLION = 'CHF-27';

    private const string FRANCS = 'CHF-28';

    private const string HEURES = 'CHF-29';

    private const string MINUTES = 'CHF-30';

    /** Un montant en francs, à lire tel quel (sans décimales). */
    public static function montant(int $francs): array
    {
        return [...self::nombre(abs($francs)), self::FRANCS];
    }

    /** Une heure « 14 h 05 » → « quatorze heures cinq minutes ». */
    public static function heure(int $hour, int $minute): array
    {
        $words = [...self::nombre($hour), self::HEURES];

        if ($minute > 0) {
            $words = [...$words, ...self::nombre($minute), self::MINUTES];
        }

        return $words;
    }

    /** Un nombre entier quelconque, sans unité (ex. un décompte d'articles). */
    public static function nombre(int $n): array
    {
        if ($n === 0) {
            return [self::UNITS[0]];
        }

        $groups = [];
        $rest = $n;

        while ($rest > 0) {
            $groups[] = $rest % 1000;
            $rest = intdiv($rest, 1000);
        }

        $words = [];
        // Du groupe le plus significatif (millions, milliards…) au moins significatif.
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            $group = $groups[$i];

            if ($group === 0) {
                continue;
            }

            if ($i === 0) {
                $words = [...$words, ...self::group(($group))];

                continue;
            }

            if ($i === 1) {
                // Mille ne prend pas « un » devant (mille, pas un mille).
                if ($group !== 1) {
                    $words = [...$words, ...self::group($group)];
                }
                $words[] = self::MILLE;

                continue;
            }

            // Millions : « un million », toujours avec le nombre devant.
            // Les milliards et plus ne sont pas traduits (montants hors de portée d'un commerce).
            $words = [...$words, ...self::group($group), self::MILLION];
        }

        return $words === [] ? [self::UNITS[0]] : $words;
    }

    /** Un groupe de 0 à 999. */
    private static function group(int $n): array
    {
        if ($n < 100) {
            return self::tens($n);
        }

        $hundreds = intdiv($n, 100);
        $rest = $n % 100;
        $words = $hundreds === 1 ? [self::CENT] : [self::UNITS[$hundreds], self::CENT];

        if ($rest > 0) {
            $words = [...$words, ...self::tens($rest)];
        }

        return $words;
    }

    /** De 0 à 99. */
    private static function tens(int $n): array
    {
        if ($n <= 16) {
            return [self::UNITS[$n]];
        }

        if ($n < 20) {
            // 17, 18, 19 → dix-sept, dix-huit, dix-neuf.
            return [self::UNITS[10], self::UNITS[$n - 10]];
        }

        $ten = intdiv($n, 10) * 10;
        $unit = $n % 10;

        if (in_array($ten, [20, 30, 40, 50, 60], true)) {
            $tenCode = match ($ten) {
                20 => 'CHF-18', 30 => 'CHF-19', 40 => 'CHF-20', 50 => 'CHF-21', 60 => 'CHF-22',
            };

            if ($unit === 0) {
                return [$tenCode];
            }

            if ($unit === 1) {
                return [$tenCode, self::ET, self::UNITS[1]];
            }

            return [$tenCode, self::UNITS[$unit]];
        }

        if ($ten === 70) {
            // Soixante-dix à soixante-dix-neuf ; « soixante et onze » pour 71.
            if ($unit === 1) {
                return ['CHF-22', self::ET, self::UNITS[11]];
            }

            return ['CHF-22', ...self::tens(10 + $unit)];
        }

        // 80 à 99 : quatre-vingt(s), quatre-vingt-dix… jamais de « et ».
        if ($unit === 0 && $ten === 80) {
            return ['CHF-23'];
        }

        if ($ten === 80) {
            return ['CHF-23', self::UNITS[$unit]];
        }

        // 90 à 99 : quatre-vingt-dix à quatre-vingt-dix-neuf.
        return ['CHF-23', ...self::tens(10 + $unit)];
    }
}
