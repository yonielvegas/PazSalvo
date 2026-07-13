<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PazSalvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPazSalvoValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_loads_without_login(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/home'));
    }

    public function test_manual_validation_form_loads_without_login(): void
    {
        $this->get(route('public.paz-salvo.validate'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('public/validate'));
    }

    public function test_manual_validation_rejects_invalid_folio_format(): void
    {
        $this->post(route('public.paz-salvo.validate.submit'), [
            'folio' => 'PS-000001-2026',
            'fecha_emision' => now()->toDateString(),
        ])->assertSessionHasErrors([
            'folio' => 'Ingrese el folio con el formato CC-000000-2026.',
        ]);
    }

    public function test_manual_validation_with_matching_folio_and_issued_date_shows_certificate_result(): void
    {
        $document = PazSalvo::factory()->create(['issued_at' => now()->setTime(14, 30)]);

        $this->post(route('public.paz-salvo.validate.submit'), [
            'folio' => $document->folio,
            'fecha_emision' => $document->issued_at->format('d/m/Y'),
        ])->assertOk()->assertInertia(fn ($page) => $page
            ->component('public/verify')
            ->where('certificate.folio', $document->folio)
            ->where('certificate.status', 'valid')
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'public_validation.succeeded',
            'subject_type' => PazSalvo::class,
            'subject_id' => $document->id,
        ]);
    }

    public function test_manual_validation_with_wrong_data_shows_generic_message(): void
    {
        PazSalvo::factory()->create(['folio' => 'CC-000001-2026', 'issued_at' => now()]);

        $this->from(route('public.paz-salvo.validate'))
            ->post(route('public.paz-salvo.validate.submit'), [
                'folio' => 'CC-000001-2026',
                'fecha_emision' => now()->subDay()->toDateString(),
            ])->assertRedirect(route('public.paz-salvo.validate'))
            ->assertSessionHas('validation_not_found', 'No se encontró un Paz y Salvo con los datos ingresados. Revise el folio o la fecha de emisión.')
            ->assertSessionHas('validation_not_found_id');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'public_validation.failed',
        ]);
        $this->assertSame('public_validation.failed', AuditLog::latest('id')->first()->event);
    }

    public function test_manual_validation_not_found_redirect_does_not_throw_type_error(): void
    {
        $this->from(route('public.paz-salvo.validate'))
            ->post(route('public.paz-salvo.validate.submit'), [
                'folio' => 'CC-999999-2026',
                    'fecha_emision' => now()->toDateString(),
                ])->assertRedirect(route('public.paz-salvo.validate'))
            ->assertSessionHas('validation_not_found')
            ->assertSessionHas('validation_not_found_id');
    }

    public function test_repeated_not_found_manual_validations_generate_distinct_flash_ids(): void
    {
        $first = $this->from(route('public.paz-salvo.validate'))
            ->post(route('public.paz-salvo.validate.submit'), [
                'folio' => 'CC-999998-2026',
                'fecha_emision' => now()->toDateString(),
            ]);

        $firstId = $first->getSession()->get('validation_not_found_id');

        $second = $this->from(route('public.paz-salvo.validate'))
            ->post(route('public.paz-salvo.validate.submit'), [
                'folio' => 'CC-999998-2026',
                'fecha_emision' => now()->toDateString(),
            ]);

        $first->assertRedirect(route('public.paz-salvo.validate'));
        $second->assertRedirect(route('public.paz-salvo.validate'));
        $this->assertNotSame($firstId, $second->getSession()->get('validation_not_found_id'));
    }

    public function test_manual_validation_form_receives_not_found_flash_for_modal(): void
    {
        $message = 'No se encontró un Paz y Salvo con los datos ingresados. Revise el folio o la fecha de emisión.';
        $id = 'validation-attempt-1';

        $this->withSession(['validation_not_found' => $message, 'validation_not_found_id' => $id])
            ->get(route('public.paz-salvo.validate'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/validate')
                ->where('flash.validation_not_found', $message)
                ->where('flash.validation_not_found_id', $id)
            );
    }

    public function test_public_manual_validation_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->from(route('public.paz-salvo.validate'))
                ->post(route('public.paz-salvo.validate.submit'), [
                    'folio' => 'CC-999999-2026',
                    'fecha_emision' => now()->toDateString(),
                ])->assertRedirect(route('public.paz-salvo.validate'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post(route('public.paz-salvo.validate.submit'), [
            'folio' => 'CC-999999-2026',
            'fecha_emision' => now()->toDateString(),
        ])->assertTooManyRequests();
    }

    public function test_public_verify_with_certificate_has_navigation_to_validate_another(): void
    {
        $document = PazSalvo::factory()->create();

        $this->get(route('public.certificates.verify', $document->verification_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/verify')
                ->where('certificate.folio', $document->folio)
                ->where('certificate.status', 'valid')
            );

        $source = file_get_contents(resource_path('js/pages/public/verify.tsx'));

        $this->assertStringContainsString('Validar otro Paz y Salvo', $source);
        $this->assertStringContainsString('href="/validar-paz-salvo"', $source);
    }

    public function test_invalid_qr_token_still_shows_public_not_found_result(): void
    {
        $this->get(route('public.certificates.verify', 'token-invalido'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('public/verify')
                ->where('certificate', null)
            );
    }
}
