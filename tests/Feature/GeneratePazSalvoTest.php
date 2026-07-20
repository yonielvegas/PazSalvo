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

    public function test_private_root_redirects_to_login_for_guests(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_private_root_redirects_authenticated_user_to_consultation(): void
    {
        $user = User::factory()->create();
        Permission::create(['name' => 'consultar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo('consultar paz y salvo');

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get('/')
            ->assertRedirect(route('paz-salvo.index'));
    }

    public function test_legacy_public_verification_routes_are_not_registered_in_private_monolith(): void
    {
        $document = PazSalvo::factory()->create();

        $this->get('/validar-paz-salvo')->assertNotFound();
        $this->get('/verificar/'.$document->verification_token)->assertNotFound();
        $this->get('/verificar/'.$document->verification_token.'/pdf')->assertNotFound();
    }

    public function test_authorized_user_can_cancel_once_and_file_is_preserved(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generated/certificate.pdf', '%PDF-test');
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'anular paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $document = PazSalvo::factory()->create(['pdf_path' => 'generated/certificate.pdf']);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->patch(route('paz-salvos.cancel', $document), ['cancel_reason' => 'Corrección administrativa requerida'])
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertDatabaseHas('paz_salvos', ['id' => $document->id, 'status' => PazSalvo::CANCELLED, 'cancelled_by' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'paz_salvo.cancelled', 'subject_id' => $document->id]);
        Storage::disk('local')->assertExists('generated/certificate.pdf');

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->patch(route('paz-salvos.cancel', $document), ['cancel_reason' => 'Segundo intento inválido'])
            ->assertSessionHasErrors('cancel_reason');
    }
}
