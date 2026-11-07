<?php

namespace App\Integrations\AaudOauth\Exceptions;

use RuntimeException;

final class AaudOauthRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly ?string $remoteCode = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(match ($reason) {
            'disabled' => 'La integración AAUD OAUTH está deshabilitada.',
            'configuration' => 'La configuración HTTP de AAUD OAUTH no es válida o segura.',
            'invalid_operation' => 'La operación no cumple el contrato local.',
            'unauthorized' => 'AAUD OAUTH rechazó el token técnico.',
            'forbidden' => 'El cliente técnico no está autorizado para esta operación.',
            'not_found' => 'AAUD OAUTH no encontró la identidad o el acceso registrado.',
            'version_conflict' => 'La versión entra en conflicto con AAUD OAUTH.',
            'idempotency_conflict' => 'El UUID de operación entra en conflicto con AAUD OAUTH.',
            'conflict' => 'AAUD OAUTH rechazó la operación por un conflicto.',
            'invalid_request' => 'AAUD OAUTH rechazó la solicitud.',
            'rate_limited' => 'AAUD OAUTH limitó la frecuencia de solicitudes.',
            'temporary' => 'AAUD OAUTH no está disponible temporalmente.',
            'timeout' => 'Se agotó el tiempo de la solicitud a AAUD OAUTH; el resultado remoto es desconocido.',
            'connection' => 'Falló la conexión con AAUD OAUTH; no hay confirmación de la operación.',
            'invalid_response' => 'AAUD OAUTH devolvió un recibo inválido; no hay confirmación de la operación.',
            default => 'Respuesta HTTP inesperada de AAUD OAUTH; no hay confirmación de la operación.',
        });
        // Never chain transport errors or keep request/response objects and authorization headers.
    }

    /** Conservative delivery classification; 5xx and missing/invalid receipts can follow an applied PUT. */
    public function isDeliveryUncertain(): bool
    {
        return in_array($this->reason, ['timeout', 'connection', 'temporary', 'invalid_response', 'unexpected_response'], true);
    }

    public function isDefinitiveRejection(): bool
    {
        return in_array($this->reason, ['unauthorized', 'forbidden', 'not_found', 'version_conflict',
            'idempotency_conflict', 'conflict', 'invalid_request', 'rate_limited'], true);
    }
}
