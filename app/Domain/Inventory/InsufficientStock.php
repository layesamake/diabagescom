<?php

namespace App\Domain\Inventory;

use App\Domain\BusinessRuleViolation;

/** Sortie refusée : elle rendrait le stock négatif sans autorisation explicite. */
final class InsufficientStock extends BusinessRuleViolation
{
    public function __construct(string $product, string $available, string $requested)
    {
        parent::__construct(__('inventory.errors.insufficient', [
            'product' => $product,
            'available' => $available,
            'requested' => $requested,
        ]));
    }
}
