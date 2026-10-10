<?php

namespace App\Domain\Tenancy;

use LogicException;

/**
 * Levée quand une donnée d'entreprise est lue ou écrite sans contexte tenant :
 * l'isolation échoue fermée plutôt que de renvoyer les données de tous les tenants.
 */
final class MissingTenantContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('No tenant context: tenant-owned data cannot be accessed.');
    }
}
