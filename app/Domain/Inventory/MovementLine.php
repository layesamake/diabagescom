<?php

namespace App\Domain\Inventory;

/**
 * Ligne d'une opération de stock. delta > 0 : entrée ; delta < 0 : sortie.
 * unitCost (XOF entier) n'est utilisé que pour les entrées valorisées ;
 * null = coût moyen courant (ou coût standard de l'article s'il est nul).
 */
final readonly class MovementLine
{
    public function __construct(
        public string $warehouseId,
        public string $productId,
        public Quantity $delta,
        public ?int $unitCost = null,
    ) {}
}
