<?php

namespace App\Integrations\AaudAuth\Oidc;

use RuntimeException;

final class OidcAuthenticationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        // No response bodies, authorization codes, tokens, secrets or chained transport exceptions.
        parent::__construct('No se pudo completar el inicio de sesión institucional.');
    }
}
