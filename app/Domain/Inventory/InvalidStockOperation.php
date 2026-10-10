<?php

namespace App\Domain\Inventory;

use App\Domain\BusinessRuleViolation;

/** Opération de stock incohérente (statut, quantité, dépôt…). */
final class InvalidStockOperation extends BusinessRuleViolation {}
