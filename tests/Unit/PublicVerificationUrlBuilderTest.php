<?php

namespace Tests\Unit;

use App\Services\PublicVerificationUrlBuilder;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class PublicVerificationUrlBuilderTest extends TestCase
{
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
        foreach (['', 'javascript:alert(1)', 'file:///tmp/verificar', 'https://user:pass@example.com/verificar', 'https://example.com/verificar#frag', 'http://invalid host.aaud.local/verificar'] as $url) {
            Config::set('paz_salvo.public_verification_base_url', $url);
            try {
                app(PublicVerificationUrlBuilder::class)->build($this->token);
                $this->fail("Expected URL to be rejected: {$url}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_allows_internal_http_in_production(): void
    {
        Config::set('app.env', 'production');
        Config::set('paz_salvo.public_verification_base_url', 'http://pazsalvo-public.aaud.local/verificar');

        $this->assertSame(
            'http://pazsalvo-public.aaud.local/verificar/'.$this->token,
            app(PublicVerificationUrlBuilder::class)->build($this->token)
        );
    }

    public function test_rejects_external_http_in_production(): void
    {
        Config::set('app.env', 'production');
        Config::set('paz_salvo.public_verification_base_url', 'http://public.example.com/verificar');

        $this->expectException(InvalidArgumentException::class);
        app(PublicVerificationUrlBuilder::class)->build($this->token);
    }

    public function test_requires_verificar_path(): void
    {
        Config::set('paz_salvo.public_verification_base_url', 'https://public.test/validar');

        $this->expectException(InvalidArgumentException::class);
        app(PublicVerificationUrlBuilder::class)->build($this->token);
    }

    public function test_production_domain_supports_http_and_https_from_configuration(): void
    {
        Config::set('app.env', 'production');
        foreach (['http', 'https'] as $scheme) {
            Config::set('paz_salvo.public_verification_base_url', $scheme.'://pazysalvo.aaud.gob.pa/verificar');
            $this->assertSame($scheme.'://pazysalvo.aaud.gob.pa/verificar/'.$this->token, app(PublicVerificationUrlBuilder::class)->build($this->token));
        }
    }

    public function test_similar_hosts_and_query_components_are_rejected(): void
    {
        Config::set('app.env', 'production');
        foreach (['http://pazysalvo.aaud.gob.pa.evil.test/verificar', 'http://evil-pazysalvo.aaud.gob.pa/verificar', 'http://pazysalvo.aaud.gob.pa/verificar?redirect=evil'] as $url) {
            Config::set('paz_salvo.public_verification_base_url', $url);
            try {
                app(PublicVerificationUrlBuilder::class)->build($this->token);
                $this->fail('Unsafe URL accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
