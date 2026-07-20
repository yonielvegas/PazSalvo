<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

        return $user;
    }

    public function test_history_and_download_are_global_for_authorized_users(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('generated/global.pdf', '%PDF-global');
        $agency = Agency::factory()->create(['name' => 'Otra agencia']);
        $document = PazSalvo::factory()->create(['agency_id' => $agency->id, 'pdf_path' => 'generated/global.pdf']);
        $user = $this->userWithPermission('ver detalle paz y salvo');

        $this->actingAs($user)->get(route('paz-salvos.show', $document))->assertOk();
        $this->actingAs($user)->get(route('paz-salvo.download', $document))->assertOk();
    }

    public function test_user_without_cancel_permission_cannot_cancel_by_id_manipulation(): void
    {
        $document = PazSalvo::factory()->create();
        $user = $this->userWithPermission('ver detalle paz y salvo');

        $this->actingAs($user)
            ->patch(route('paz-salvos.cancel', $document), ['cancel_reason' => 'Intento sin permiso'])
            ->assertForbidden();
    }

    public function test_admin_with_permission_accesses_user_management_without_mfa_redirect(): void
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'administrar usuarios', 'guard_name' => 'web']);
        $user->givePermissionTo('administrar usuarios');

        $this->actingAs($user)
            ->withSession(['auth_session_version' => $user->session_version, 'authenticated_session_started_at' => now()->timestamp, 'session_regenerated_at' => now()->timestamp])
            ->get('/admin/users')
            ->assertOk();
    }
}
