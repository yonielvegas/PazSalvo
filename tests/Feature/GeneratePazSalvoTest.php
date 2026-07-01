<?php

namespace Tests\Feature;

use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GeneratePazSalvoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_verification_finds_generated_certificate(): void
    {
        $document = PazSalvo::factory()->create();
        $this->get(route('public.certificates.verify', $document->verification_token))
            ->assertOk()->assertInertia(fn ($page) => $page->component('public/verify')->where('certificate.folio', $document->folio)->where('certificate.status', 'valid'));
    }

    public function test_cancelled_public_pdf_is_forbidden_and_file_is_preserved(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generated/test.pdf', '%PDF-test');
        $document = PazSalvo::factory()->create(['status' => PazSalvo::CANCELLED, 'pdf_path' => 'generated/test.pdf', 'cancelled_at' => now(), 'cancel_reason' => 'Corrección administrativa']);
        $this->get(route('public.certificates.pdf', $document->verification_token))->assertForbidden();
        Storage::disk('local')->assertExists('generated/test.pdf');
    }

    public function test_unknown_public_token_shows_not_found_state(): void
    {
        $this->get(route('public.certificates.verify', '00000000-0000-4000-8000-000000000000'))
            ->assertOk()->assertInertia(fn ($page) => $page->where('certificate', null));
    }

    public function test_malformed_public_token_does_not_reach_uuid_query(): void
    {
        $this->get(route('public.certificates.verify', 'token-invalido'))
            ->assertOk()->assertInertia(fn ($page) => $page->where('certificate', null));
        $this->get(route('public.certificates.pdf', 'token-invalido'))->assertNotFound();
    }

    public function test_authorized_user_can_cancel_once_and_public_pdf_is_blocked(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generated/certificate.pdf', '%PDF-test');
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'anular paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $document = PazSalvo::factory()->create(['pdf_path' => 'generated/certificate.pdf']);

        $this->actingAs($user)->patch(route('paz-salvos.cancel', $document), ['cancel_reason' => 'Corrección administrativa requerida'])
            ->assertRedirect()->assertSessionHas('message');
        $this->assertDatabaseHas('paz_salvos', ['id' => $document->id, 'status' => PazSalvo::CANCELLED, 'cancelled_by' => $user->id]);
        Storage::disk('local')->assertExists('generated/certificate.pdf');
        $this->get(route('public.certificates.pdf', $document->verification_token))->assertForbidden();
        $this->actingAs($user)->patch(route('paz-salvos.cancel', $document), ['cancel_reason' => 'Segundo intento inválido'])->assertSessionHasErrors('cancel_reason');
    }
}
