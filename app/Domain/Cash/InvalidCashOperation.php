<?php

namespace App\Domain\Cash;

use App\Domain\BusinessRuleViolation;

/** Opération de caisse refusée (caisse fermée, espèces insuffisantes, écart non justifié…). */
final class InvalidCashOperation extends BusinessRuleViolation {}
