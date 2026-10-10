<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Événement financier ou de stock : jamais modifié ni supprimé (doublé par un
 * trigger PostgreSQL). Une erreur se corrige par une opération inverse liée.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(class_basename(static::class).' is append-only.'));
        static::deleting(fn () => throw new LogicException(class_basename(static::class).' is append-only.'));
    }
}
