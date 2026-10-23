<?php

namespace App\Integrations\AaudAuth\Exceptions;

use RuntimeException;

final class InvalidRoleCatalogException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'empty' => 'El catálogo web está vacío; requiere una política explícita antes de cualquier reemplazo remoto.',
            'too_large' => 'El catálogo supera el límite de 1000 roles de AAUD AUTH.',
            'invalid_record' => 'El catálogo contiene un registro que no corresponde a un rol del guard web.',
            'invalid_code' => 'El catálogo contiene un código ausente o inválido.',
            'duplicate_code' => 'El catálogo contiene códigos duplicados.',
            'invalid_name' => 'El catálogo contiene un nombre vacío o inválido.',
            'invalid_state' => 'El catálogo contiene un estado activo inválido.',
            default => 'No se puede construir un catálogo de roles confiable.',
        });
    }
}
