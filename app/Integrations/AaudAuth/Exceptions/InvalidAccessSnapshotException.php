<?php

namespace App\Integrations\AaudAuth\Exceptions;

use RuntimeException;

final class InvalidAccessSnapshotException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // Reasons are local constants; never include a supplied value or identity profile.
        parent::__construct('No se puede construir una instantánea de acceso confiable.');
    }
}
