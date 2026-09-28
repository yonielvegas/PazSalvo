<?php

namespace Tests\Feature;

use App\Models\PazSalvo;
use App\Models\User;
use App\Services\PazSalvoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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
        $user->assignRole(Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web']));

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get('/')
            ->assertRedirect(route('paz-salvo.index'));
    }

    public function test_public_verification_routes_are_available_in_private_monolith(): void
    {
        $document = PazSalvo::factory()->create();

        $this->get('/verificar')->assertOk();
        $this->get('/validar-paz-salvo')->assertOk();
        $this->get('/verificar/'.$document->verification_token)->assertOk()->assertSee('CERTIFICADO VIGENTE');
        $this->get('/verificar/'.$document->verification_token.'/pdf')->assertNotFound();
    }

    public function test_authorized_user_can_cancel_once_and_file_is_preserved(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generated/certificate.pdf', '%PDF-test');
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'anular paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $user->assignRole(Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web']));
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

    public function test_generation_failure_returns_error_and_preserves_query_for_retry(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'generar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo('generar paz y salvo');
        $user->assignRole(Role::firstOrCreate(['name' => 'operador', 'guard_name' => 'web']));
        $token = (string) Str::uuid();
        $service = \Mockery::mock(PazSalvoService::class);
        $service->shouldReceive('generate')->once()->andThrow(new \RuntimeException('storage failure'));
        $this->app->instance(PazSalvoService::class, $service);
        $this->actingAs($user)->withSession([
            'paz_salvo_query' => ['token' => $token, 'client_number' => '34787', 'expires_at' => now()->addMinute()->timestamp],
            'paz_salvo_result' => ['query_token' => $token, 'status' => 'debt_free'],
            'auth_session_version' => $user->session_version,
            'authenticated_session_started_at' => now()->timestamp,
            'session_regenerated_at' => now()->timestamp,
        ])->from('/paz-salvos/consultar')->post('/paz-salvos/generar', ['query_token' => $token, 'numero_factura' => '123456'])
            ->assertRedirect('/paz-salvos/consultar')
            ->assertSessionHasErrors(['generation' => 'No se pudo generar el certificado. No se emitió ningún Paz y Salvo. Intente nuevamente.'])
            ->assertSessionHas('paz_salvo_query.token', $token)->assertSessionHas('result.status', 'debt_free');
        $this->assertDatabaseCount('paz_salvos', 0);
    }
}
