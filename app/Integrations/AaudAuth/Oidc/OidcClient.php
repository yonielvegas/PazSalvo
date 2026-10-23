<?php

namespace App\Integrations\AaudAuth\Oidc;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class OidcClient
{
    public function __construct(private readonly IdTokenValidator $validator) {}

    public function configuration(): array
    {
        $issuer = config('aaud-oidc.issuer');
        $redirect = config('aaud-oidc.redirect_uri');
        $secret = config('aaud-oidc.client_secret');
        $connect = filter_var(config('aaud-auth.connect_timeout'), FILTER_VALIDATE_INT);
        $timeout = filter_var(config('aaud-auth.timeout'), FILTER_VALIDATE_INT);
        $ca = config('aaud-auth.ca_bundle');
        if (config('aaud-oidc.enabled') !== true || ! $this->safeUrl($issuer) || ! $this->safeUrl($redirect)
            || ! preg_match('~/realms/[A-Za-z0-9_-]+$~D', parse_url($issuer, PHP_URL_PATH) ?? '')
            || config('aaud-oidc.client_id') !== 'pazysalvo-web'
            || $redirect !== rtrim((string) config('app.url'), '/').'/auth/aaud/callback'
            || ($secret !== null && (! is_string($secret) || strlen($secret) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $secret)))
            || $connect === false || $connect < 1 || $connect > 30 || $timeout === false || $timeout < $connect || $timeout > 60
            || config('aaud-auth.verify_tls') !== true
            || ($ca && (! is_string($ca) || str_contains($ca, '://') || ! is_file($ca) || ! is_readable($ca)))
            || config('session.driver') === 'cookie'
            || (config('app.env') !== 'testing' && in_array(config('cache.default'), ['array', 'null', 'memo'], true))) {
            throw new OidcAuthenticationException('configuration');
        }

        return ['issuer' => $issuer, 'redirect_uri' => $redirect, 'client_id' => 'pazysalvo-web', 'client_secret' => $secret,
            'connect' => $connect, 'timeout' => $timeout, 'verify' => $ca ?: true];
    }

    private function safeUrl(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $local = ($parts['scheme'] ?? '') === 'http' && in_array(config('app.env'), ['local', 'testing'], true)
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);

        return (($parts['scheme'] ?? '') === 'https' || $local) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment']) && ! str_ends_with($url, '/');
    }

    private function request(#[SensitiveParameter] array $config): PendingRequest
    {
        return Http::acceptJson()->connectTimeout($config['connect'])->timeout($config['timeout'])
            ->withOptions(['allow_redirects' => false, 'verify' => $config['verify'], 'http_errors' => false]);
    }

    private function json(#[SensitiveParameter] Response $response): array
    {
        try {
            if ($response->status() !== 200 || strlen($response->body()) > 131072) {
                throw new OidcAuthenticationException('provider');
            }
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($body) || array_is_list($body) || array_key_exists('error', $body)) {
                throw new OidcAuthenticationException('provider');
            }

            return $body;
        } catch (Throwable) {
            throw new OidcAuthenticationException('provider');
        }
    }

    private function discovery(#[SensitiveParameter] array $config): array
    {
        $metadata = $this->json($this->request($config)->get($config['issuer'].'/.well-known/openid-configuration'));
        foreach (['issuer' => $config['issuer'], 'authorization_endpoint' => $config['issuer'].'/protocol/openid-connect/auth',
            'token_endpoint' => $config['issuer'].'/protocol/openid-connect/token', 'jwks_uri' => $config['issuer'].'/protocol/openid-connect/certs'] as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new OidcAuthenticationException('discovery');
            }
        }
        foreach (['response_types_supported' => 'code', 'code_challenge_methods_supported' => 'S256', 'id_token_signing_alg_values_supported' => 'RS256'] as $key => $value) {
            if (! is_array($metadata[$key] ?? null) || ! in_array($value, $metadata[$key], true)) {
                throw new OidcAuthenticationException('discovery');
            }
        }

        return $metadata;
    }

    public function authorizationUrl(#[SensitiveParameter] array $flow): string
    {
        try {
            $config = $this->configuration();
            $metadata = $this->discovery($config);

            return $metadata['authorization_endpoint'].'?'.http_build_query(['response_type' => 'code', 'response_mode' => 'query',
                'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'], 'scope' => 'openid',
                'state' => $flow['state'], 'nonce' => $flow['nonce'], 'code_challenge_method' => 'S256',
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '=')], '', '&', PHP_QUERY_RFC3986);
        } catch (Throwable) {
            throw new OidcAuthenticationException('provider');
        }
    }

    public function identity(#[SensitiveParameter] array $flow, #[SensitiveParameter] string $code): array
    {
        try {
            $config = $this->configuration();
            foreach (['issuer', 'client_id', 'redirect_uri'] as $field) {
                if (($flow[$field] ?? null) !== $config[$field]) {
                    throw new OidcAuthenticationException('configuration_changed');
                }
            }
            $metadata = $this->discovery($config);
            $request = $this->request($config)->asForm();
            if ($config['client_secret']) {
                $request = $request->withBasicAuth(rawurlencode($config['client_id']), rawurlencode($config['client_secret']));
            }
            $tokens = $this->json($request->post($metadata['token_endpoint'], ['grant_type' => 'authorization_code', 'code' => $code,
                'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'], 'code_verifier' => $flow['verifier']]));
            if (! is_string($tokens['id_token'] ?? null) || strlen($tokens['id_token']) > 65536
                || ! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '' || strlen($tokens['access_token']) > 65536
                || ! is_string($tokens['token_type'] ?? null) || strcasecmp($tokens['token_type'], 'Bearer') !== 0
                || ! is_int($tokens['expires_in'] ?? null) || $tokens['expires_in'] < 1) {
                throw new OidcAuthenticationException('token_response');
            }
            $jwks = $this->json($this->request($config)->get($metadata['jwks_uri']));

            return $this->validator->validate($tokens['id_token'], $jwks, $flow, $tokens['access_token'], $code);
        } catch (Throwable) {
            throw new OidcAuthenticationException('authentication');
        }
    }

    public function __debugInfo(): array
    {
        return ['client' => 'pazysalvo-web'];
    }
}
