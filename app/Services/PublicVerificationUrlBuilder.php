<?php

namespace App\Services;

use Illuminate\Support\Str;
use InvalidArgumentException;

class PublicVerificationUrlBuilder
{
    public function build(string $token): string
    {
        if (! Str::isUuid($token)) {
            throw new InvalidArgumentException('El token de verificación no tiene un formato válido.');
        }

        $baseUrl = trim((string) config('paz_salvo.public_verification_base_url'));
        if ($baseUrl === '') {
            throw new InvalidArgumentException('La URL pública de verificación no está configurada.');
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('La URL pública de verificación no es válida.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('La URL pública de verificación usa un esquema no permitido.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('La URL pública de verificación contiene componentes no permitidos.');
        }

        if (config('app.env') === 'production' && $scheme !== 'https') {
            throw new InvalidArgumentException('La URL pública de verificación debe usar HTTPS en producción.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if ($path === '' || ! str_ends_with($path, '/verificar')) {
            throw new InvalidArgumentException('La URL pública de verificación debe terminar en /verificar.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return "{$scheme}://{$parts['host']}{$port}{$path}/".rawurlencode($token).$query;
    }
}
