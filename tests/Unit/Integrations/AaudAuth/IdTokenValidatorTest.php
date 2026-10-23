<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Oidc\IdTokenValidator;
use App\Integrations\AaudAuth\Oidc\OidcAuthenticationException;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OidcFixture as Idp;

class IdTokenValidatorTest extends TestCase
{
    private function flow(): array
    {
        return ['issuer' => Idp::ISSUER, 'client_id' => 'pazysalvo-web', 'nonce' => 'random-nonce', 'issued_at' => time()];
    }

    public function test_verifies_real_rsa_signature_and_returns_only_issuer_and_subject(): void
    {
        $result = (new IdTokenValidator)->validate(Idp::token($this->flow()), Idp::jwks(), $this->flow(), 'access', 'code');
        $this->assertSame(['issuer' => Idp::ISSUER, 'subject' => 'institutional-subject'], $result);
    }

    public static function invalidClaims(): array
    {
        return [[['iss' => 'https://evil.example/realms/aaud']], [['sub' => '']], [['sub' => ['invalid']]],
            [['aud' => 'pazysalvo-sync']], [['aud' => ['pazysalvo-web', 'other'], 'azp' => null]], [['azp' => 'other']],
            [['nonce' => 'wrong']], [['nonce' => null]], [['exp' => 1]], [['exp' => null]], [['exp' => '9999999999']],
            [['iat' => 1]], [['iat' => 9999999999]], [['iat' => null]], [['nbf' => 9999999999]],
            [['auth_time' => 9999999999]], [['typ' => 'Bearer']], [['at_hash' => 'wrong']], [['c_hash' => 'wrong']]];
    }

    #[DataProvider('invalidClaims')]
    public function test_invalid_claims_are_rejected_without_leaking_tokens(array $claims): void
    {
        $token = Idp::token($this->flow(), $claims);
        try {
            (new IdTokenValidator)->validate($token, Idp::jwks(), $this->flow(), 'private-access-token', 'private-code');
            $this->fail('Invalid token accepted');
        } catch (OidcAuthenticationException $failure) {
            $this->assertNull($failure->getPrevious());
            $this->assertStringNotContainsString($token, (string) $failure);
            $this->assertStringNotContainsString('private-access-token', (string) $failure);
        }
    }

    public function test_valid_optional_token_hashes_and_multiple_audiences(): void
    {
        $hash = fn ($s) => rtrim(strtr(base64_encode(substr(hash('sha256', $s, true), 0, 16)), '+/', '-_'), '=');
        $token = Idp::token($this->flow(), ['aud' => ['pazysalvo-web', 'other'], 'at_hash' => $hash('access'), 'c_hash' => $hash('code')]);
        $this->assertSame('institutional-subject', (new IdTokenValidator)->validate($token, Idp::jwks(), $this->flow(), 'access', 'code')['subject']);
    }

    public function test_hmac_algorithm_confusion_and_forged_signatures_are_rejected(): void
    {
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        foreach ([JWT::encode(Idp::claims($this->flow()), str_repeat('x', 32), 'HS256', 'test-rsa'),
            JWT::encode(Idp::claims($this->flow()), $wrongKey, 'RS256', 'test-rsa'), 'not.a.jwt'] as $token) {
            try {
                (new IdTokenValidator)->validate($token, Idp::jwks(), $this->flow(), 'access', 'code');
                $this->fail('Invalid signature accepted');
            } catch (OidcAuthenticationException $failure) {
                $this->assertNull($failure->getPrevious());
            }
        }
    }

    public function test_unknown_kid_and_encryption_keys_cannot_verify_id_tokens(): void
    {
        foreach ([['keys' => []], ['keys' => [array_replace(Idp::jwks()['keys'][0], ['kid' => 'other'])]],
            ['keys' => [array_replace(Idp::jwks()['keys'][0], ['use' => 'enc'])]]] as $jwks) {
            $this->expectFailure($jwks);
        }
    }

    private function expectFailure(array $jwks): void
    {
        try {
            (new IdTokenValidator)->validate(Idp::token($this->flow()), $jwks, $this->flow(), 'access', 'code');
            $this->fail('Invalid JWKS accepted');
        } catch (OidcAuthenticationException $failure) {
            $this->assertNull($failure->getPrevious());
        }
    }
}
