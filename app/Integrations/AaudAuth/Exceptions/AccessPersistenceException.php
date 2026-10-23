<?php

namespace App\Integrations\AaudAuth\Exceptions;

final class AccessPersistenceException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('La operación de acceso requiere revisión: '.$reason.'.');
    }
}
