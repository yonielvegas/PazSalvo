<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

/** Ephemeral RSA test identity provider. Never uses institutional keys or credentials. */
final class OidcFixture
{
    public const ISSUER = 'https://keycloak.example.test/realms/aaud';

    private static ?\OpenSSLAsymmetricKey $key = null;

    public static function key(): \OpenSSLAsymmetricKey
    {
        return self::$key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public static function jwks(): array
    {
        $rsa = openssl_pkey_get_details(self::key())['rsa'];
        $encode = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return ['keys' => [['kid' => 'test-rsa', 'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])]]];
    }

    public static function metadata(): array
    {
        return ['issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => self::ISSUER.'/protocol/openid-connect/token', 'jwks_uri' => self::ISSUER.'/protocol/openid-connect/certs',
            'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'id_token_signing_alg_values_supported' => ['RS256']];
    }

    public static function claims(array $flow): array
    {
        return ['iss' => self::ISSUER, 'sub' => 'institutional-subject', 'aud' => 'pazysalvo-web', 'azp' => 'pazysalvo-web',
            'nonce' => $flow['nonce'], 'iat' => time(), 'exp' => time() + 300, 'auth_time' => time() - 3600, 'typ' => 'ID',
            'realm_access' => ['roles' => ['admin']], 'email' => 'untrusted@example.test'];
    }

    public static function token(array $flow, array $changes = []): string
    {
        return JWT::encode(array_replace(self::claims($flow), $changes), self::key(), 'RS256', 'test-rsa');
    }
}
