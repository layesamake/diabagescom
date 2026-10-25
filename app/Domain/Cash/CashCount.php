<?php

namespace App\Domain\Cash;

/**
 * Comptage de l'argent du tiroir par billet (mode simple, maquette 06b) :
 * billets de 10 000, 5 000, 2 000, 1 000 et 500 F, et pièces de 100 F.
 *
 * Le total est toujours recalculé côté serveur : un client ne peut pas
 * annoncer un montant différent de celui de son comptage.
 */
final class CashCount
{
    /** Valeurs comptées, de la plus grande à la plus petite (clé du JSON : la valeur en francs). */
    public const array DENOMINATIONS = [10000, 5000, 2000, 1000, 500, 100];

    /** Plus grand nombre de billets d'une même valeur accepté (garde-fou de saisie). */
    public const int MAX_PER_DENOMINATION = 100000;

    /**
     * Règles de validation d'un comptage envoyé sous la clé $field.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $field = 'count'): array
    {
        $rules = [$field => ['array:'.implode(',', self::DENOMINATIONS)]];

        foreach (self::DENOMINATIONS as $value) {
            $rules["{$field}.{$value}"] = ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PER_DENOMINATION];
        }

        return $rules;
    }

    /**
     * Comptage complet (toutes les valeurs, entiers) à partir d'une saisie déjà validée.
     * Les clés sont les valeurs en francs : PHP range « 10000 » parmi les clés entières.
     *
     * @param  array<int|string, mixed>  $input
     * @return array<int, int>
     */
    public static function normalize(array $input): array
    {
        $count = [];

        foreach (self::DENOMINATIONS as $value) {
            $count[$value] = max(0, (int) ($input[$value] ?? 0));
        }

        return $count;
    }

    /**
     * Total en francs d'un comptage.
     *
     * @param  array<int, int>  $count
     */
    public static function total(array $count): int
    {
        $total = 0;

        foreach (self::DENOMINATIONS as $value) {
            $total += $value * (int) ($count[$value] ?? 0);
        }

        return $total;
    }
}
