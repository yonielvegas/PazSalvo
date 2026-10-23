<?php

namespace App\Integrations\AaudAuth\Keycloak;

use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException as AuthenticationFailure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use LogicException;
use SensitiveParameter;

final class TechnicalTokenProvider
{
    private ?TechnicalToken $token = null;

    private ?string $configurationFingerprint = null;

    public function getToken(?string $requiredScope = null): TechnicalToken
    {
        // Validate even when cached: disabling or changing credentials takes effect immediately.
        $config = $this->configuration();
        if ($requiredScope !== null && ! in_array($requiredScope, ['access.write', 'roles.write', 'directory.read'], true)) {
            throw new AuthenticationFailure(AuthenticationFailure::CONFIGURATION, configurationFields: ['scope']);
        }
        $config['required_scope'] = $requiredScope;
        $fingerprint = hash('sha256', serialize($config));
        $startedAt = now()->toDateTimeImmutable();
        if ($fingerprint === $this->configurationFingerprint && $this->token?->isValid($config['leeway'], $startedAt)) {
            return $this->token;
        }
        $this->token = null;
        $this->configurationFingerprint = null;

        try {
            $response = Http::asForm()->acceptJson()
                ->connectTimeout($config['connect_timeout'])->timeout($config['timeout'])
                ->withOptions(['verify' => $config['verify'], 'allow_redirects' => false, 'http_errors' => false])
                ->post($config['url'], [
                    'grant_type' => 'client_credentials',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    ...($requiredScope === null ? [] : ['scope' => $requiredScope]),
                ]);
        } catch (ConnectionException $exception) {
            throw new AuthenticationFailure($this->isTimeout($exception) ? AuthenticationFailure::TIMEOUT : AuthenticationFailure::CONNECTION);
        }

        $token = $this->readResponse($response, $startedAt, $config['leeway'], $requiredScope);
        $this->configurationFingerprint = $fingerprint;

        return $this->token = $token;
    }

    private function configuration(): array
    {
        if (config('aaud-auth.enabled') !== true) {
            $this->token = null;
            $this->configurationFingerprint = null;
            throw new AuthenticationFailure(AuthenticationFailure::DISABLED);
        }

        $url = config('aaud-auth.token_url');
        $clientId = config('aaud-auth.client_id');
        $secret = config('aaud-auth.client_secret');
        $integer = fn ($value) => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        $connect = $integer(config('aaud-auth.connect_timeout'));
        $timeout = $integer(config('aaud-auth.timeout'));
        $leeway = $integer(config('aaud-auth.token_expiry_leeway'));
        $ca = config('aaud-auth.ca_bundle');
        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
        $localHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array(config('app.env'), ['local', 'testing'], true)
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);

        $checks = [
            'AAUD_AUTH_TOKEN_URL' => is_array($parts) && ($localHttp || ($parts['scheme'] ?? '') === 'https')
                && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']),
            'AAUD_AUTH_CLIENT_ID' => $clientId === 'pazysalvo-sync',
            'AAUD_AUTH_CLIENT_SECRET' => is_string($secret) && trim($secret) !== '',
            'AAUD_AUTH_CONNECT_TIMEOUT' => $connect !== false && $connect > 0,
            'AAUD_AUTH_TIMEOUT' => $timeout !== false && $timeout > 0 && $timeout >= $connect,
            'AAUD_AUTH_TOKEN_EXPIRY_LEEWAY' => $leeway !== false && $leeway >= 0,
            'AAUD_AUTH_VERIFY_TLS' => config('aaud-auth.verify_tls') === true,
            'AAUD_AUTH_CA_BUNDLE' => $ca === null || $ca === '' || (is_string($ca) && ! str_contains($ca, '://') && is_file($ca) && is_readable($ca)),
        ];
        $invalid = array_keys(array_filter($checks, fn ($valid) => ! $valid));
        if ($invalid !== []) {
            throw new AuthenticationFailure(AuthenticationFailure::CONFIGURATION, configurationFields: $invalid);
        }

        return ['url' => $url, 'client_id' => $clientId, 'client_secret' => $secret,
            'connect_timeout' => $connect, 'timeout' => $timeout, 'leeway' => $leeway, 'verify' => $ca ?: true];
    }

    private function readResponse(#[SensitiveParameter] Response $response, DateTimeImmutable $startedAt, int $leeway, ?string $requiredScope = null): TechnicalToken
    {
        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new AuthenticationFailure(AuthenticationFailure::TEMPORARY, $status);
        }
        if (in_array($status, [401, 403], true)) {
            throw new AuthenticationFailure(AuthenticationFailure::REJECTED, $status);
        }
        try {
            $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthenticationFailure(AuthenticationFailure::INVALID_RESPONSE, $status);
        }
        if (is_array($data) && in_array($data['error'] ?? null, ['invalid_client', 'unauthorized_client', 'invalid_grant', 'invalid_scope'], true)) {
            throw new AuthenticationFailure(AuthenticationFailure::REJECTED, $status);
        }
        if ($status !== 200 || ! is_array($data) || isset($data['error'])
            || ! is_string($data['access_token'] ?? null) || ! preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $data['access_token'])
            || ! is_string($data['token_type'] ?? null) || strcasecmp($data['token_type'], 'Bearer') !== 0
            || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= $leeway
            || $data['expires_in'] > PHP_INT_MAX - $startedAt->getTimestamp()) {
            throw new AuthenticationFailure(AuthenticationFailure::INVALID_RESPONSE, $status);
        }
        if ($requiredScope !== null && (! is_string($data['scope'] ?? null)
            || ! in_array($requiredScope, explode(' ', $data['scope']), true))) {
            throw new AuthenticationFailure(AuthenticationFailure::REJECTED, $status);
        }
        $token = new TechnicalToken($data['access_token'], $data['token_type'], $startedAt->setTimestamp($startedAt->getTimestamp() + $data['expires_in']));
        if (! $token->isValid($leeway, now()->toDateTimeImmutable())) {
            throw new AuthenticationFailure(AuthenticationFailure::INVALID_RESPONSE, $status);
        }

        return $token;
    }

    private function isTimeout(#[SensitiveParameter] ConnectionException $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (method_exists($cause, 'getHandlerContext') && ($cause->getHandlerContext()['errno'] ?? null) === 28) {
                return true;
            }
        }

        // Stream handlers may not expose cURL context. Inspect, but never propagate, their message.
        return preg_match('/timed?\s*out|timeout/i', $exception->getMessage()) === 1;
    }

    public function __debugInfo(): array
    {
        return ['token' => $this->token ? '[redacted]' : null];
    }

    public function __serialize(): array
    {
        throw new LogicException('No se permite serializar proveedores de tokens técnicos.');
    }
}
