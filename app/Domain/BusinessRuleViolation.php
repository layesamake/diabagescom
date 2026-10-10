<?php

namespace App\Domain;

use RuntimeException;

/**
 * Refus d'une règle métier (stock insuffisant, document déjà validé…).
 * Le message est destiné à l'utilisateur ; rendu en 422 (API) ou en message
 * d'erreur sur la page précédente (interface).
 */
abstract class BusinessRuleViolation extends RuntimeException {}
