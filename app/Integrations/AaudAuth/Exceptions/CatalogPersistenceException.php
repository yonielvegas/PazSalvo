<?php

namespace App\Integrations\AaudAuth\Exceptions;

use RuntimeException;

final class CatalogPersistenceException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'unverified' => 'La versión remota del catálogo todavía no está verificada.',
            'unresolved' => 'Existe una operación de catálogo sin resolver.',
            'approval' => 'Se requiere un administrador activo y una referencia de verificación válida.',
            'version' => 'La versión observada es inválida, regresiva o está agotada.',
            'integrity' => 'La integridad de la operación persistida no es válida.',
            'receipt' => 'El recibo no corresponde a la operación y su instantánea persistida.',
            'claim' => 'La concesión de procesamiento no es válida o ha expirado.',
            'reconciliation' => 'La cancelación requiere una versión remota verificada que impida aplicar intentos inciertos tardíos.',
            'transition' => 'La transición o su fecha de próximo intento no es válida.',
            default => 'No se encontró el estado u operación de catálogo solicitado.',
        });
    }
}
