<?php

namespace Tests\Unit;

use App\Services\PublicVerificationUrlBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class PublicVerificationUrlBuilderTest extends TestCase
{
    use RefreshDatabase;

    private string $token = '00000000-0000-4000-8000-000000000000';

    public function test_builds_public_url_from_dedicated_configuration(): void
    {
        Config::set('paz_salvo.public_verification_base_url', 'http://public.test:8001/verificar/');
        Config::set('app.url', 'http://private.test');

        $this->assertSame(
            'http://public.test:8001/verificar/'.$this->token,
            app(PublicVerificationUrlBuilder::class)->build($this->token)
        );
    }

    public function test_rejects_empty_or_unsafe_urls(): void
    {
        foreach (['', 'javascript:alert(1)', 'file:///tmp/verificar', 'https://user:pass@example.com/verificar', 'https://example.com/verificar#frag'] as $url) {
            Config::set('paz_salvo.public_verification_base_url', $url);
            try {
                app(PublicVerificationUrlBuilder::class)->build($this->token);
                $this->fail("Expected URL to be rejected: {$url}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_rejects_http_in_production(): void
    {
        Config::set('app.env', 'production');
        Config::set('paz_salvo.public_verification_base_url', 'http://public.test/verificar');

        $this->expectException(InvalidArgumentException::class);
        app(PublicVerificationUrlBuilder::class)->build($this->token);
    }

    public function test_requires_verificar_path(): void
    {
        Config::set('paz_salvo.public_verification_base_url', 'https://public.test/validar');

        $this->expectException(InvalidArgumentException::class);
        app(PublicVerificationUrlBuilder::class)->build($this->token);
    }
}
