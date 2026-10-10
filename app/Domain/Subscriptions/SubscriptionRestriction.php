<?php

namespace App\Domain\Subscriptions;

use RuntimeException;

/**
 * Refus liés à l'abonnement. Le message est destiné à l'utilisateur (français clair).
 */
abstract class SubscriptionRestriction extends RuntimeException {}
