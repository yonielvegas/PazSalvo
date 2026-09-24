<?php

namespace Tests\Unit;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('env', 'production');
        config([
            'app.env' => 'production',
            'app.key' => 'base64:9QlrOmDX0DRvadFpDqLnY9fm+brfhGV24dgnkRdMzOE=',
            'app.url' => 'http://pazsalvo.aaud.local',
            'app.debug' => false,
            'session.secure' => false,
            'paz_salvo.public_verification_base_url' => 'http://pazsalvo-public.aaud.local/verificar',
            'security.allowed_hosts' => ['pazsalvo.aaud.local'],
            'security.internal_network.allowed_ips' => ['10.0.0.0/8'],
            'security.temporary_user_password' => 'fixture-only',
        ]);
    }

    public function test_internal_http_allows_non_secure_session_cookie(): void
    {
        $this->bootProvider();
        $this->assertTrue(true);
    }

    public function test_https_requires_secure_session_cookie(): void
    {
        config(['app.url' => 'https://pazsalvo.aaud.local']);

        $this->assertProductionError('SESSION_SECURE_COOKIE');
    }

    public function test_https_accepts_secure_session_cookie(): void
    {
        config(['app.url' => 'https://pazsalvo.aaud.local', 'session.secure' => true]);

        $this->bootProvider();
        $this->assertTrue(true);
    }

    public function test_debug_must_remain_disabled_on_http(): void
    {
        config(['app.debug' => true]);

        $this->assertProductionError('APP_DEBUG');
    }

    public function test_debug_must_be_explicitly_false(): void
    {
        config(['app.debug' => null]);

        $this->assertProductionError('APP_DEBUG');
    }

    public function test_other_required_production_settings_remain_required(): void
    {
        config(['security.allowed_hosts' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ALLOWED_HOSTS');
        $this->bootProvider();
    }

    public function test_invalid_application_url_is_rejected(): void
    {
        config(['app.url' => 'file:///tmp/app']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL');
        $this->bootProvider();
    }

    private function bootProvider(): void
    {
        (new AppServiceProvider($this->app))->boot();
    }

    private function assertProductionError(string $name): void
    {
        try {
            $this->bootProvider();
            $this->fail("Expected {$name} to be rejected.");
        } catch (RuntimeException $exception) {
            $this->assertSame('Configuración de producción incompleta: '.$name, $exception->getMessage());
        }
    }
}
