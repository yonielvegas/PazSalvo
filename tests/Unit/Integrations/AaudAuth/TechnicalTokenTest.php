<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Keycloak\TechnicalToken;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TechnicalTokenTest extends TestCase
{
    public function test_validity_expiration_and_leeway_boundaries(): void
    {
        $now = new DateTimeImmutable('2026-10-19T12:00:00Z');
        $token = new TechnicalToken('private.opaque.token', 'bearer', $now->modify('+60 seconds'));
        $this->assertSame('private.opaque.token', $token->accessToken());
        $this->assertSame('Bearer', $token->tokenType);
        $this->assertTrue($token->isValid(30, $now));
        $this->assertFalse($token->isValid(30, $now->modify('+30 seconds')));
        $this->assertTrue($token->isValid(0, $now->modify('+59 seconds')));
        $this->assertFalse($token->isValid(0, $now->modify('+60 seconds')));
        $this->assertFalse($token->isValid(0, $now->modify('+61 seconds')));
        $this->assertSame($now->modify('+60 seconds')->getTimestamp(), $token->expiresAt->getTimestamp());
    }

    public static function invalidTokens(): array
    {
        return [['', 'Bearer'], ['with spaces', 'Bearer'], ["header\r\ninjection", 'Bearer'], ['abc', 'Basic'], ['abc', ''], ['abc', 'Bearer ']];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_data_is_rejected(string $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TechnicalToken($value, $type, new DateTimeImmutable);
    }

    public function test_negative_leeway_is_rejected(): void
    {
        $token = new TechnicalToken('opaque', 'Bearer', new DateTimeImmutable('+1 minute'));
        $this->expectException(InvalidArgumentException::class);
        $token->isValid(-1);
    }

    public function test_debug_json_string_print_and_export_do_not_expose_access_token(): void
    {
        $value = 'private.opaque.token';
        $token = new TechnicalToken($value, 'Bearer', new DateTimeImmutable('+1 minute'));
        ob_start();
        var_dump($token);
        $dump = ob_get_clean();
        foreach ([$dump, print_r($token, true), var_export($token, true), json_encode($token), (string) $token] as $representation) {
            $this->assertStringNotContainsString($value, $representation);
        }
        $this->expectException(LogicException::class);
        serialize($token);
    }

    public function test_constructor_exception_trace_redacts_invalid_credentials(): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            new TechnicalToken('private credential with spaces', 'Basic', new DateTimeImmutable);
            $this->fail('Invalid token must fail');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringNotContainsString('private credential with spaces', print_r($exception, true));
        } finally {
            ini_set('zend.exception_ignore_args', $before);
        }
    }
}
