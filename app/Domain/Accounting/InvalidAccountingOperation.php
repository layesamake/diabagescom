<?php

namespace App\Domain\Accounting;

use App\Domain\BusinessRuleViolation;

/** Opération comptable refusée (déséquilibre, période close, compte inconnu…). */
final class InvalidAccountingOperation extends BusinessRuleViolation {}
