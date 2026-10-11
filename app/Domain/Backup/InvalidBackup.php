<?php

namespace App\Domain\Backup;

use RuntimeException;

/**
 * Archive refusée à la restauration ; le message est affichable tel quel.
 */
final class InvalidBackup extends RuntimeException
{
    /**
     * @param  array<string, string>  $params
     */
    public static function because(string $reason, array $params = []): self
    {
        return new self(__("organization.restore.errors.{$reason}", $params));
    }
}
