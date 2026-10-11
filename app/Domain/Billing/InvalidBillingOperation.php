<?php

namespace App\Domain\Billing;

use App\Domain\BusinessRuleViolation;

/** Opération de facturation refusée (tarif non défini, facture déjà payée…). */
final class InvalidBillingOperation extends BusinessRuleViolation {}
