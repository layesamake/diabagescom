<?php

namespace App\Domain\Purchases;

use App\Domain\BusinessRuleViolation;

/** Opération d'achat refusée (statut, quantité reçue, facture en double…). */
final class InvalidPurchaseOperation extends BusinessRuleViolation {}
