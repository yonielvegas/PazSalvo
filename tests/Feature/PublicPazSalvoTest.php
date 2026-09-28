<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PazSalvo;
use App\Services\PazSalvoPublicVerificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PublicPazSalvoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_public_form_works_from_outside_institutional_network_without_authentication(): void
    {
        config(['security.internal_network.enabled' => true, 'security.internal_network.allowed_ips' => ['10.0.0.0/8']]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/verificar')
            ->assertOk()->assertSee('Validar Paz y Salvo')
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->get('/paz-salvos')->assertRedirect('/login');
    }

    public function test_generated_token_shows_masked_public_data_and_serves_pdf(): void
    {
        $client = Client::factory()->create(['client_number' => '1234564787', 'address' => 'PRIVATE ADDRESS']);
        $document = PazSalvo::factory()->create(['client_id' => $client->id, 'pdf_path' => 'generated/paz-salvos/certificate.pdf', 'cancel_reason' => 'PRIVATE REASON']);
        Storage::disk('local')->put($document->pdf_path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");

        $this->get('/verificar/'.$document->verification_token)->assertOk()->assertSee('CERTIFICADO VIGENTE')
            ->assertSee('******4787')->assertSee('Ver PDF')->assertDontSee('1234564787')
            ->assertDontSee('PRIVATE ADDRESS')->assertDontSee('PRIVATE REASON')->assertDontSee($document->pdf_path);
        $this->get('/verificar/'.$document->verification_token.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $payload = app(PazSalvoPublicVerificationService::class)->publicPayload($document);
        $this->assertSame(['status', 'folio', 'client_number', 'holder_name', 'agency', 'issued_at', 'expires_at', 'cancelled_at', 'pdf_url'], array_keys($payload));
    }

    public function test_expired_and_cancelled_certificates_keep_real_state_without_public_pdf(): void
    {
        $document = PazSalvo::factory()->create();
        foreach ([
            [PazSalvo::GENERATED, now()->subDay(), 'CERTIFICADO EXPIRADO'],
            [PazSalvo::CANCELLED, now()->addDay(), 'CERTIFICADO ANULADO'],
        ] as [$status, $expires, $title]) {
            $document->update(['status' => $status, 'expires_at' => $expires]);
            $this->get('/verificar/'.$document->verification_token)->assertOk()->assertSee($title)->assertDontSee('Ver PDF');
            $this->get('/verificar/'.$document->verification_token.'/pdf')->assertNotFound();
        }
    }

    public function test_nonexistent_and_malformed_tokens_are_controlled(): void
    {
        $this->get('/verificar/'.Str::uuid())->assertOk()->assertSee('Certificado no encontrado');
        $service = Mockery::mock(PazSalvoPublicVerificationService::class)->makePartial();
        $service->shouldNotReceive('findByToken');
        $this->app->instance(PazSalvoPublicVerificationService::class, $service);
        foreach (['invalid-token', str_repeat('a', 120), '12345'] as $token) {
            $this->get('/verificar/'.$token)->assertOk()->assertSee('Certificado no encontrado');
            $this->get('/verificar/'.$token.'/pdf')->assertNotFound();
        }
    }

    public function test_technical_records_are_not_public_certificates(): void
    {
        $document = PazSalvo::factory()->create();
        foreach ([PazSalvo::ERROR, PazSalvo::PROCESSING] as $status) {
            $document->update(['status' => $status]);
            $this->get('/verificar/'.$document->verification_token)->assertOk()->assertSee('Certificado no encontrado')->assertDontSee($document->folio);
            $this->get('/verificar/'.$document->verification_token.'/pdf')->assertNotFound();
        }
    }

    public function test_manual_lookup_normalizes_folio_and_matches_panama_issue_date(): void
    {
        $document = PazSalvo::factory()->create(['folio' => 'CC-000008-2026']);
        DB::table('paz_salvos')->where('id', $document->id)->update(['issued_at' => '2026-07-21 02:00:00+00']);
        $this->post('/validar-paz-salvo', ['_token' => 'csrf', 'folio' => ' 0000082026 ', 'fecha_emision' => ' 20/07/2026 '])
            ->assertOk()->assertSee('CERTIFICADO VIGENTE')->assertSee($document->folio);
        $this->from('/verificar')->post('/validar-paz-salvo', ['folio' => $document->folio, 'fecha_emision' => '21/07/2026'])
            ->assertRedirect('/verificar')->assertSessionHas('not_found_message');
    }

    public function test_manual_lookup_rejects_invalid_dates_and_unexpected_inputs(): void
    {
        foreach ([
            ['folio' => 'CC-000008-2026', 'fecha_emision' => '31/02/2026'],
            ['folio' => 'CC-000008-2026', 'fecha_emision' => now()->addDay()->format('d/m/Y')],
            ['folio' => ['CC-000008-2026'], 'fecha_emision' => '20/07/2026'],
            ['folio' => "CC-000008-2026' OR 1=1", 'fecha_emision' => '20/07/2026'],
            ['folio' => 'CC-000008-2026', 'fecha_emision' => '20/07/2026', 'extra' => 'x'],
        ] as $input) {
            $this->post('/validar-paz-salvo', $input)->assertSessionHasErrors();
        }
    }

    public function test_rate_limits_are_preserved(): void
    {
        config(['public.rate_limits.manual' => 2, 'public.rate_limits.qr' => 2, 'public.rate_limits.pdf' => 2]);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/validar-paz-salvo', [])->assertSessionHasErrors();
            $this->get('/verificar/invalid')->assertOk();
            $this->get('/verificar/invalid/pdf')->assertNotFound();
        }
        $this->post('/validar-paz-salvo', [])->assertTooManyRequests();
        $this->get('/verificar/invalid')->assertTooManyRequests();
        $this->get('/verificar/invalid/pdf')->assertTooManyRequests();
    }

    public function test_missing_pdf_is_logged_and_does_not_break_public_result(): void
    {
        $document = PazSalvo::factory()->create(['pdf_path' => 'generated/paz-salvos/missing.pdf']);
        Log::spy();
        $this->get('/verificar/'.$document->verification_token)->assertOk()->assertSee('CERTIFICADO VIGENTE')->assertDontSee('Ver PDF');
        $this->get('/verificar/'.$document->verification_token.'/pdf')->assertNotFound();
        Log::shouldHaveReceived('warning')->with('Public Paz y Salvo PDF unavailable.', Mockery::on(fn ($context) => $context['reason'] === 'missing_file'))->twice();
    }

    public function test_pdf_paths_and_content_are_checked(): void
    {
        $service = app(PazSalvoPublicVerificationService::class);
        foreach (['../private.pdf', '/tmp/private.pdf', 'general-admin-signatures/private.pdf', 'generated/paz-salvos/../../private.pdf'] as $path) {
            $this->assertNull($service->resolvePdfPath(new PazSalvo(['pdf_path' => $path])));
        }
        Storage::disk('local')->put('generated/paz-salvos/fake.pdf', '<html>not a PDF</html>');
        $this->assertNull($service->resolvePdfPath(new PazSalvo(['pdf_path' => 'generated/paz-salvos/fake.pdf'])));
        Storage::disk('local')->put('private.pdf', '%PDF-1.4');
        symlink(Storage::disk('local')->path('private.pdf'), Storage::disk('local')->path('generated/paz-salvos/link.pdf'));
        $this->assertNull($service->resolvePdfPath(new PazSalvo(['pdf_path' => 'generated/paz-salvos/link.pdf'])));
    }

    public function test_database_failure_is_controlled_and_does_not_expose_sql(): void
    {
        $service = Mockery::mock(PazSalvoPublicVerificationService::class)->makePartial();
        $service->shouldReceive('findByToken')->andThrow(new QueryException('pgsql', 'select secret from paz_salvos', [], new \Exception('down')));
        $this->app->instance(PazSalvoPublicVerificationService::class, $service);
        $this->get('/verificar/'.Str::uuid())->assertServiceUnavailable()->assertSee('Servicio no disponible')->assertDontSee('select secret');
    }

    public function test_public_output_escapes_holder_name(): void
    {
        $client = Client::factory()->create(['holder_name' => '<script>alert(1)</script>']);
        $document = PazSalvo::factory()->create(['client_id' => $client->id]);
        $this->get('/verificar/'.$document->verification_token)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
