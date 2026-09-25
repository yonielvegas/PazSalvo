<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_reports_an_executable_document_converter(): void
    {
        Storage::fake('local');
        config([
            'paz-salvo.disk' => 'local',
            'paz-salvo.libreoffice_binary' => PHP_BINARY,
            'security.internal_network.enabled' => false,
        ]);

        $this->get('/healthz')
            ->assertOk()
            ->assertJsonPath('checks.libreoffice', true)
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['release'])
            ->assertJsonMissingPath('checks.libreoffice_path');
    }

    public function test_health_check_fails_without_exposing_a_missing_binary_path(): void
    {
        Storage::fake('local');
        $missing = '/nonexistent/private/libreoffice';
        config([
            'paz-salvo.disk' => 'local',
            'paz-salvo.libreoffice_binary' => $missing,
            'security.internal_network.enabled' => false,
        ]);

        $this->get('/healthz')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.libreoffice', false)
            ->assertDontSee($missing);
    }
}
