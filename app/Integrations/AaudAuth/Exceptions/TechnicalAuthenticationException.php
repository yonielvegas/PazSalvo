<?php

namespace App\Integrations\AaudAuth\Exceptions;

use RuntimeException;

final class TechnicalAuthenticationException extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const CONFIGURATION = 'configuration';

    public const CONNECTION = 'connection';

    public const TIMEOUT = 'timeout';

    public const REJECTED = 'credentials_rejected';

    public const INVALID_RESPONSE = 'invalid_response';

    public const TEMPORARY = 'temporary_provider_error';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly array $configurationFields = [],
    ) {
        $message = match ($reason) {
            self::DISABLED => 'La integración AAUD AUTH está deshabilitada.',
            self::CONFIGURATION => 'La configuración técnica de AAUD AUTH está incompleta o no es segura.',
            self::CONNECTION => 'No se pudo conectar con el proveedor de tokens.',
            self::TIMEOUT => 'Se agotó el tiempo de conexión o respuesta del proveedor de tokens.',
            self::REJECTED => 'El proveedor rechazó la autenticación del cliente técnico.',
            self::TEMPORARY => 'El proveedor de tokens no está disponible temporalmente.',
            default => 'El proveedor devolvió una respuesta OAuth inválida.',
        };

        // No previous exception: transport exceptions may contain the complete OAuth request.
        parent::__construct($message);
    }
}
