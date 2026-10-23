<?php

namespace App\Integrations\AaudAuth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use SensitiveParameter;
use Throwable;

final class IdTokenValidator
{
    /** Verify with the maintained JOSE library; return only the federated identity, never the tokens. */
    public function validate(#[SensitiveParameter] string $token, array $jwks, array $flow, #[SensitiveParameter] string $accessToken, #[SensitiveParameter] string $code): array
    {
        try {
            $keys = $jwks['keys'] ?? null;
            if (! is_array($keys) || ! array_is_list($keys) || count($keys) > 32) {
                throw new OidcAuthenticationException('keys');
            }
            $trusted = [];
            $seen = [];
            foreach ($keys as $key) {
                if (! is_array($key) || ($key['kty'] ?? null) !== 'RSA' || ($key['use'] ?? 'sig') !== 'sig'
                    || ($key['alg'] ?? 'RS256') !== 'RS256') {
                    continue;
                }
                if (! is_string($key['kid'] ?? null) || $key['kid'] === '' || isset($seen[$key['kid']])
                    || (isset($key['key_ops']) && (! is_array($key['key_ops']) || ! in_array('verify', $key['key_ops'], true)))) {
                    throw new OidcAuthenticationException('keys');
                }
                $seen[$key['kid']] = true;
                $trusted[] = $key;
            }
            $headers = null;
            $claims = (array) JWT::decode($token, JWK::parseKeySet(['keys' => $trusted], 'RS256'), $headers);
            $now = time();
            if (isset($headers->crit) || isset($headers->jku) || isset($headers->jwk) || isset($headers->x5u)
                || (isset($headers->typ) && $headers->typ !== 'JWT')
                || ($claims['iss'] ?? null) !== $flow['issuer']
                || ! is_string($claims['sub'] ?? null) || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/D', $claims['sub'])
                || ! is_string($claims['nonce'] ?? null) || ! hash_equals($flow['nonce'], $claims['nonce'])
                || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
                || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now || $claims['iat'] < $flow['issued_at'] - 30
                || $claims['exp'] <= $claims['iat']
                || (isset($claims['nbf']) && (! is_int($claims['nbf']) || $claims['nbf'] > $now || $claims['nbf'] >= $claims['exp']))
                || (isset($claims['auth_time']) && (! is_int($claims['auth_time']) || $claims['auth_time'] < 0 || $claims['auth_time'] > $claims['iat']))
                || (isset($claims['typ']) && $claims['typ'] !== 'ID')) {
                throw new OidcAuthenticationException('claims');
            }
            $audience = is_string($claims['aud'] ?? null) ? [$claims['aud']] : ($claims['aud'] ?? null);
            if (! is_array($audience) || ! array_is_list($audience) || $audience === []
                || count(array_filter($audience, fn ($aud) => is_string($aud) && $aud !== '')) !== count($audience)
                || count(array_unique($audience)) !== count($audience) || ! in_array($flow['client_id'], $audience, true)
                || (count($audience) > 1 && ($claims['azp'] ?? null) !== $flow['client_id'])
                || (array_key_exists('azp', $claims) && $claims['azp'] !== $flow['client_id'])) {
                throw new OidcAuthenticationException('audience');
            }
            foreach (['at_hash' => $accessToken, 'c_hash' => $code] as $claim => $value) {
                if (array_key_exists($claim, $claims)) {
                    $expected = rtrim(strtr(base64_encode(substr(hash('sha256', $value, true), 0, 16)), '+/', '-_'), '=');
                    if (! is_string($claims[$claim]) || ! hash_equals($expected, $claims[$claim])) {
                        throw new OidcAuthenticationException('token_binding');
                    }
                }
            }

            return ['issuer' => $claims['iss'], 'subject' => $claims['sub']];
        } catch (Throwable) {
            throw new OidcAuthenticationException('invalid_id_token');
        }
    }
}
