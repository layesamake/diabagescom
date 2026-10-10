<?php

namespace App\Domain\Sales;

use App\Domain\Inventory\Quantity;

/**
 * Calculs de montants en XOF entiers, sans flottant.
 *
 * - Ventes : prix catalogue TTC ; HT = TTC × 10000 / (10000 + taux), arrondi au
 *   franc le plus proche ; TVA = TTC − HT (le total TTC affiché au client est exact).
 * - Achats : coût HT ; TVA = HT × taux / 10000, arrondie au franc.
 *
 * Taux en points de base (1800 = 18 %). Règles d'arrondi à faire valider par
 * l'expert-comptable (docs/05).
 */
final class Pricing
{
    /**
     * @return array{gross: int, discount: int, total: int, excl_tax: int, tax: int}
     */
    public static function saleLine(Quantity $quantity, int $unitPrice, int $discount, int $taxBasisPoints): array
    {
        $gross = $quantity->times($unitPrice);

        if ($discount < 0 || $discount > $gross) {
            throw new InvalidSalesOperation(__('sales.errors.discount_range'));
        }

        $total = $gross - $discount;
        $exclTax = self::exclusiveFromInclusive($total, $taxBasisPoints);

        return ['gross' => $gross, 'discount' => $discount, 'total' => $total, 'excl_tax' => $exclTax, 'tax' => $total - $exclTax];
    }

    /**
     * @return array{excl_tax: int, tax: int, total: int}
     */
    public static function purchaseLine(Quantity $quantity, int $unitCost, int $taxBasisPoints): array
    {
        $exclTax = $quantity->times($unitCost);
        $tax = self::taxOnExclusive($exclTax, $taxBasisPoints);

        return ['excl_tax' => $exclTax, 'tax' => $tax, 'total' => $exclTax + $tax];
    }

    /**
     * HT depuis TTC, arrondi demi vers le haut (montants positifs).
     */
    public static function exclusiveFromInclusive(int $inclusive, int $taxBasisPoints): int
    {
        $divisor = 10000 + $taxBasisPoints;

        return intdiv(2 * $inclusive * 10000 + $divisor, 2 * $divisor);
    }

    public static function taxOnExclusive(int $exclusive, int $taxBasisPoints): int
    {
        return intdiv(2 * $exclusive * $taxBasisPoints + 10000, 20000);
    }

    /**
     * Part proportionnelle d'un montant (avoir partiel) : amount × part / whole, arrondi au franc.
     */
    public static function prorate(int $amount, Quantity $part, Quantity $whole): int
    {
        $exact = bcdiv(bcmul((string) $amount, $part->value, 3), $whole->value, 3);

        return (int) bcadd($exact, '0.5', 0);
    }
}
