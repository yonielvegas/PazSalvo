<?php

namespace Tests\Feature;

use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdministrativeAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MasterDataSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole('admin');
        $this->actingAs($this->actor);
        Storage::fake(config('paz-salvo.disk'));
    }

    private function failSuccessAudit(): void
    {
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function ($event, $metadata = [], $subject = null, $request = null, $result = null) {
            if ($result === 'success') {
                throw new \RuntimeException('Injected audit failure');
            }

            return (new AuditLogger)->record($event, $metadata, $subject, $request, $result);
        });
    }

    public function test_create_and_signature_are_compensated_when_audit_fails(): void
    {
        $this->failSuccessAudit();
        $this->post('/admin/users', [
            'name' => 'Rollback', 'email' => 'rollback@example.test',
            'agency_id' => $this->actor->agency_id, 'role' => 'administrador_general',
            'general_admin_signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertServerError();
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        $this->assertDatabaseCount('general_admin_signatures', 0);
        $this->assertSame([], Storage::disk(config('paz-salvo.disk'))->allFiles());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.store.failed', 'result' => 'failed']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.created', 'result' => 'success']);
    }

    public function test_update_rolls_back_role_profile_and_version_on_audit_failure(): void
    {
        $target = User::factory()->create();
        $target->assignRole('operador');
        $version = $target->fresh()->session_version;
        $this->failSuccessAudit();
        $this->put('/admin/users/'.$target->id, [
            'name' => 'Changed', 'email' => $target->email, 'agency_id' => $target->agency_id, 'role' => 'consulta',
        ])->assertServerError();
        $this->assertSame($target->name, $target->fresh()->name);
        $this->assertTrue($target->fresh()->hasRole('operador'));
        $this->assertSame($version, $target->fresh()->session_version);
    }

    public function test_toggle_rolls_back_state_and_version_on_audit_failure(): void
    {
        $target = User::factory()->create();
        $target->assignRole('operador');
        $version = $target->fresh()->session_version;
        $this->failSuccessAudit();
        $this->patch('/admin/users/'.$target->id.'/toggle')->assertServerError();
        $this->assertTrue($target->fresh()->is_active);
        $this->assertSame($version, $target->fresh()->session_version);
    }

    public function test_missing_new_signature_does_not_deactivate_previous_general_admin(): void
    {
        $previous = User::factory()->create();
        $previous->assignRole('administrador_general');
        $signature = GeneralAdminSignature::create(['user_id' => $previous->id, 'signature_path' => 'old.png', 'is_active' => true]);
        $target = User::factory()->create();
        $target->assignRole('operador');
        $this->put('/admin/users/'.$target->id, [
            'name' => $target->name, 'email' => $target->email, 'agency_id' => $target->agency_id,
            'role' => 'administrador_general', 'confirm_replace' => true,
        ])->assertSessionHasErrors('general_admin_signature');
        $this->assertTrue($previous->fresh()->is_active);
        $this->assertTrue($signature->fresh()->is_active);
        $this->assertTrue($target->fresh()->hasRole('operador'));
    }

    public function test_general_admin_replacement_and_reactivation_are_audited(): void
    {
        $previous = User::factory()->create();
        $previous->assignRole('administrador_general');
        $signature = GeneralAdminSignature::create(['user_id' => $previous->id, 'signature_path' => 'old.png', 'is_active' => true]);
        $version = $previous->session_version;
        $this->post('/admin/users', [
            'name' => 'Replacement', 'email' => 'replacement@example.test', 'agency_id' => $this->actor->agency_id,
            'role' => 'administrador_general', 'confirm_replace' => true,
            'general_admin_signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertSessionHasNoErrors();
        $this->assertFalse($previous->fresh()->is_active);
        $this->assertFalse($signature->fresh()->is_active);
        $this->assertGreaterThan($version, $previous->fresh()->session_version);
        $this->patch('/admin/users/'.$previous->id.'/toggle', ['confirm_replace' => true])->assertSessionHasNoErrors();
        $this->assertTrue($previous->fresh()->is_active);
        $this->assertTrue($signature->fresh()->is_active);
        $this->assertSame(1, GeneralAdminSignature::where('is_active', true)->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.activated', 'subject_id' => $previous->id, 'result' => 'success']);
    }

    public function test_deletion_is_audited_and_last_admin_cannot_be_removed_through_model_methods(): void
    {
        $target = User::factory()->create();
        $target->assignRole('consulta');
        $this->delete('/admin/users/'.$target->id)->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deleted', 'subject_id' => $target->id]);
        $this->expectException(ValidationException::class);
        $this->actor->syncRoles(['consulta']);
    }

    public function test_role_permission_changes_and_session_versions_rollback_on_audit_failure(): void
    {
        $role = Role::findByName('consulta');
        $user = User::factory()->create();
        $user->assignRole($role);
        $permissions = $role->permissions()->pluck('name')->all();
        $version = $user->session_version;
        $this->failSuccessAudit();
        $this->put('/settings/roles/'.$role->id.'/permissions', ['permissions' => ['settings.view']])->assertServerError();
        $this->assertEqualsCanonicalizing($permissions, $role->fresh()->permissions()->pluck('name')->all());
        $this->assertSame($version, $user->fresh()->session_version);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'role.permissions_updated', 'result' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.permissions.failed', 'result' => 'failed']);
    }

    public function test_password_and_sessions_rollback_if_reset_audit_fails(): void
    {
        $user = User::factory()->create();
        $user->assignRole('consulta');
        DB::table('sessions')->insert(['id' => 'atomicity-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $hash = $user->password;
        $version = $user->session_version;
        $this->failSuccessAudit();
        $this->patch('/admin/users/'.$user->id.'/reset-password')->assertServerError();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($version, $user->fresh()->session_version);
        $this->assertDatabaseHas('sessions', ['id' => 'atomicity-session']);
    }

    public function test_general_admin_toggle_failure_preserves_both_users_and_signatures(): void
    {
        $current = User::factory()->create();
        $current->assignRole('administrador_general');
        $currentSignature = GeneralAdminSignature::create(['user_id' => $current->id, 'signature_path' => 'current.png', 'is_active' => true]);
        $previous = User::factory()->create(['is_active' => false]);
        $previous->assignRole('administrador_general');
        $previousSignature = GeneralAdminSignature::create(['user_id' => $previous->id, 'signature_path' => 'previous.png', 'is_active' => false]);
        $this->failSuccessAudit();
        $this->patch('/admin/users/'.$previous->id.'/toggle', ['confirm_replace' => true])->assertServerError();
        $this->assertTrue($current->fresh()->is_active);
        $this->assertTrue($currentSignature->fresh()->is_active);
        $this->assertFalse($previous->fresh()->is_active);
        $this->assertFalse($previousSignature->fresh()->is_active);
    }

    public function test_role_endpoint_rejects_code_changes_and_keeps_code_when_renamed(): void
    {
        $role = Role::create(['name' => 'custom', 'guard_name' => 'web']);
        $this->put('/settings/roles/'.$role->id, ['name' => 'renamed', 'code' => 'different'])->assertSessionHasErrors('code');
        $this->assertSame('custom', $role->fresh()->name);
        $this->put('/settings/roles/'.$role->id, ['name' => 'renamed'])->assertSessionHasNoErrors();
        $this->assertSame('custom', $role->fresh()->code);
        $this->assertSame('renamed', $role->fresh()->name);
    }

    public function test_releasing_sessions_rolls_back_if_audit_fails(): void
    {
        $user = User::factory()->create();
        $user->assignRole('consulta');
        DB::table('sessions')->insert(['id' => 'release-rollback', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->failSuccessAudit();
        $this->patch('/admin/users/'.$user->id.'/release-session')->assertServerError();
        $this->assertDatabaseHas('sessions', ['id' => 'release-rollback']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.session_released', 'result' => 'success']);
    }

    public function test_user_with_certificates_cannot_be_deleted_and_keeps_role_and_version(): void
    {
        $user = User::factory()->create();
        $user->assignRole('operador');
        $version = $user->session_version;
        PazSalvo::factory()->create(['generated_by' => $user->id]);
        $this->delete('/admin/users/'.$user->id)->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame($version, $user->fresh()->session_version);
        $this->assertTrue($user->fresh()->hasRole('operador'));
    }
}
