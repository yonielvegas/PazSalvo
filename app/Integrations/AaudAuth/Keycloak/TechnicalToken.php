<?php

namespace App\Integrations\AaudAuth\Keycloak;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;
use WeakMap;

final class TechnicalToken implements JsonSerializable
{
    // var_export ignores __debugInfo. Keep the credential outside instance properties too.
    private static ?WeakMap $values = null;

    public readonly string $tokenType;

    public function __construct(
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] string $tokenType,
        public readonly DateTimeImmutable $expiresAt,
    ) {
        if (! preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $accessToken) || strcasecmp($tokenType, 'Bearer') !== 0) {
            throw new InvalidArgumentException('Datos de token técnico inválidos.');
        }

        $this->tokenType = 'Bearer';
        self::$values ??= new WeakMap;
        self::$values[$this] = $accessToken;
    }

    public function accessToken(): string
    {
        return self::$values[$this];
    }

    public function isValid(int $leeway = 0, ?DateTimeImmutable $now = null): bool
    {
        if ($leeway < 0) {
            throw new InvalidArgumentException('El margen de expiración no puede ser negativo.');
        }

        return $this->expiresAt->getTimestamp() - ($now ?? new DateTimeImmutable)->getTimestamp() > $leeway;
    }

    public function __debugInfo(): array
    {
        return ['access_token' => '[redacted]', 'token_type' => $this->tokenType, 'expires_at' => $this->expiresAt->format(DATE_ATOM)];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __toString(): string
    {
        return 'TechnicalToken [redacted]';
    }

    public function __serialize(): array
    {
        throw new LogicException('No se permite serializar tokens técnicos.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('No se permite deserializar tokens técnicos.');
    }

    private function __clone() {}
}
