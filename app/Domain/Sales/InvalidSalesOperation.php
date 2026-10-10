<?php

namespace App\Domain\Sales;

use App\Domain\BusinessRuleViolation;

/** Opération de vente refusée (statut, remise, crédit, paiement…). */
final class InvalidSalesOperation extends BusinessRuleViolation {}
