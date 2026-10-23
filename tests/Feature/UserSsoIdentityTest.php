<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Services\AuditLogger;
use App\Services\UserSsoIdentityManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserSsoIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $target;

    private const ISSUER = 'https://keycloak.example.test/realms/aaud';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['aaud-auth' => ['enabled' => true, 'api_url' => 'https://auth.example.test',
            'issuer' => self::ISSUER, 'token_url' => self::ISSUER.'/protocol/openid-connect/token',
            'client_id' => 'pazysalvo-sync', 'client_secret' => 'fixture-secret', 'connect_timeout' => 5,
            'timeout' => 10, 'verify_tls' => true, 'ca_bundle' => null, 'token_expiry_leeway' => 30]]);
        $role = Role::create(['name' => 'operador', 'guard_name' => 'web']);
        $this->actor = User::factory()->create();
        $this->actor->assignRole($role);
        $this->actor->givePermissionTo(Permission::create(['name' => 'administrar usuarios', 'guard_name' => 'web']));
        $this->target = User::factory()->create();
        $this->target->assignRole($role);
        $this->target->givePermissionTo(Permission::create(['name' => 'consultar paz y salvo', 'guard_name' => 'web']));
        $this->actingAs($this->actor);
    }

    private function candidate(array $changes = []): array
    {
        return array_replace(['issuer' => self::ISSUER, 'subject' => 'verified-subject', 'username' => 'directory-user',
            'name' => 'Directory User', 'name_is_fallback' => false, 'email' => null, 'enabled' => true], $changes);
    }

    private function selection(array $changes = []): array
    {
        return array_replace(['issuer' => self::ISSUER, 'subject' => 'verified-subject', 'query' => 'directory', 'page' => 1, 'confirmed' => true], $changes);
    }

    private function directory(?array $rows = null, int $status = 200): void
    {
        Http::fake([
            self::ISSUER.'/protocol/openid-connect/token' => Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300]),
            'https://auth.example.test/api/integrations/v1/directory/users*' => Http::response([
                'data' => $rows ?? [$this->candidate()], 'page' => 1, 'limit' => 20, 'has_more' => false], $status),
        ]);
    }

    private function url(?User $user = null): string
    {
        return '/admin/users/'.($user ?? $this->target)->id.'/sso-identity';
    }

    private function request(): Request
    {
        $request = Request::create($this->url(), 'POST');
        $request->setUserResolver(fn () => $this->actor);

        return $request;
    }

    public function test_explicit_link_and_unlink_preserve_local_security_and_audit_both_results(): void
    {
        $this->directory();
        $before = $this->target->fresh()->getAttributes();
        $roles = $this->target->roles->modelKeys();
        $permissions = $this->target->permissions->modelKeys();
        $this->post($this->url(), [...$this->selection(), 'email' => 'attacker@example.test', 'role' => 'admin'])->assertSessionHasNoErrors()->assertRedirect();
        $identity = $this->target->ssoIdentity()->sole();
        $this->assertSame($this->actor->id, $identity->created_by);
        $this->assertSame('verified-subject', $identity->subject);
        $this->getJson($this->url())->assertOk()->assertJsonPath('identity.subject', 'verified-subject')->assertHeader('Cache-Control', 'no-store, private');
        $audit = AuditLog::where('event', 'user.sso.linked')->sole();
        $this->assertSame($this->actor->id, $audit->actor_user_id);
        $this->assertSame('success', $audit->result);
        $this->assertSame('verified-subject', $audit->metadata['subject']);
        config(['aaud-auth.enabled' => false]);
        $this->delete($this->url(), ['identity_id' => $identity->id, 'confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('user_sso_identities', 0);
        $after = $this->target->fresh()->getAttributes();
        foreach (['name', 'username', 'session_version', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertSame($before, $after);
        $this->assertSame('Directory User', $this->target->fresh()->name);
        $this->assertSame('directory-user', $this->target->fresh()->username);
        $this->assertSame($roles, $this->target->fresh()->roles->modelKeys());
        $this->assertSame($permissions, $this->target->fresh()->permissions->modelKeys());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.sso.unlinked', 'result' => 'success', 'actor_user_id' => $this->actor->id]);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => ! in_array($request->method(), ['GET', 'POST']) || ($request->method() === 'POST' && ! str_ends_with($request->url(), '/token')));
    }

    public function test_search_displays_verified_candidates_without_writing_links_or_trusting_extra_fields(): void
    {
        $this->directory([$this->candidate(['directory_id' => 'private', 'token' => 'must-not-leak'])]);
        $this->getJson('/admin/sso-directory?query=directory&page=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissing(['directory_id' => 'private'])->assertJsonMissing(['token' => 'must-not-leak']);
        $this->assertDatabaseCount('user_sso_identities', 0);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r['q'] === 'directory' && $r['limit'] === 20 && $r->hasHeader('Authorization', 'Bearer fixture-token'));
    }

    public function test_link_reverifies_search_instead_of_trusting_previous_selection(): void
    {
        $this->directory([]);
        $this->post($this->url(), $this->selection())->assertSessionHasErrors('identity');
        $this->assertDatabaseCount('user_sso_identities', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.sso.linked.failed', 'result' => 'failed']);
    }

    public function test_disabled_directory_identity_cannot_be_linked(): void
    {
        $this->directory([$this->candidate(['enabled' => false])]);
        $this->post($this->url(), $this->selection())->assertSessionHasErrors('identity');
        $this->assertDatabaseCount('user_sso_identities', 0);
    }

    public function test_untrusted_issuer_is_rejected_before_network_access(): void
    {
        $this->post($this->url(), $this->selection(['issuer' => 'https://attacker.example.test']))->assertSessionHasErrors('identity');
        Http::assertNothingSent();
    }

    public function test_existing_link_is_not_silently_overwritten(): void
    {
        $this->directory();
        $old = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'old', 'created_by' => $this->actor->id]);
        $this->post($this->url(), $this->selection())->assertSessionHasErrors('identity');
        $this->assertSame($old->id, $this->target->ssoIdentity()->sole()->id);
    }

    public function test_identity_cannot_be_assigned_to_another_local_user(): void
    {
        $this->directory();
        UserSsoIdentity::create(['user_id' => $this->actor->id, 'issuer' => self::ISSUER, 'subject' => 'verified-subject']);
        $this->post($this->url(), $this->selection())->assertSessionHasErrors('identity');
        $this->assertDatabaseCount('user_sso_identities', 1);
    }

    public function test_stale_unlink_confirmation_cannot_delete_a_replacement(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'replacement']);
        $this->delete($this->url(), ['identity_id' => $link->id + 1, 'confirmed' => true])->assertSessionHasErrors('identity');
        $this->assertDatabaseHas('user_sso_identities', ['id' => $link->id]);
        Http::assertNothingSent();
    }

    public function test_both_mutations_require_explicit_confirmation(): void
    {
        $this->post($this->url(), $this->selection(['confirmed' => false]))->assertSessionHasErrors('confirmed');
        $this->delete($this->url(), ['identity_id' => 1])->assertSessionHasErrors('confirmed');
        Http::assertNothingSent();
    }

    public function test_permission_is_required_for_every_endpoint_and_anonymous_requests(): void
    {
        $this->actingAs($this->target);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson('/admin/sso-directory?query=directory')->assertForbidden();
        $this->post($this->url(), $this->selection())->assertForbidden();
        $this->delete($this->url(), ['identity_id' => 1, 'confirmed' => true])->assertForbidden();
        auth()->logout();
        $this->getJson($this->url())->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_directory_unavailable_or_disabled_fails_closed(): void
    {
        $this->directory([], 403);
        $this->getJson('/admin/sso-directory?query=directory')->assertStatus(503);
        $this->post($this->url(), $this->selection())->assertSessionHasErrors('identity');
        $this->assertDatabaseCount('user_sso_identities', 0);
        config(['aaud-auth.enabled' => false]);
        Http::fake();
        $this->post($this->url(), $this->selection())->assertSessionHasErrors('identity');
        Http::assertNothingSent();
    }

    public function test_permission_revoked_during_verification_is_rechecked_under_admin_lock(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300]);
            }
            $this->actor->revokePermissionTo('administrar usuarios');

            return Http::response(['data' => [$this->candidate()], 'page' => 1, 'limit' => 20, 'has_more' => false]);
        });
        $this->post($this->url(), $this->selection())->assertForbidden();
        $this->assertDatabaseCount('user_sso_identities', 0);
    }

    public function test_audit_failure_rolls_back_link_and_unlink(): void
    {
        $this->directory();
        $logger = \Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('record')->andThrow(new \RuntimeException('fixture audit failure'));
        $this->app->instance(AuditLogger::class, $logger);
        $manager = app(UserSsoIdentityManager::class);
        try {
            $manager->link($this->target, $this->actor, $this->selection(), $this->request());
            $this->fail('Audit failure must abort linking');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('user_sso_identities', 0);
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'old']);
        try {
            $manager->unlink($this->target, $this->actor, $link->id, $this->request());
            $this->fail('Audit failure must abort unlinking');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture audit failure', $e->getMessage());
        }
        $this->assertDatabaseHas('user_sso_identities', ['id' => $link->id]);
        $this->assertSame(0, AuditLog::where('event', 'like', 'user.sso.%')->where('result', 'success')->count());
    }

    public function test_outer_transaction_rollback_also_reverts_link_and_success_audit(): void
    {
        $this->directory();
        try {
            DB::transaction(function () {
                app(UserSsoIdentityManager::class)->link($this->target, $this->actor, $this->selection(), $this->request());
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('user_sso_identities', 0);
        $this->assertSame(0, AuditLog::where('event', 'user.sso.linked')->count());
    }

    public function test_postgres_uniqueness_and_references_cannot_be_bypassed(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'first']);
        foreach ([['user_id' => $this->target->id, 'subject' => 'second'], ['user_id' => $this->actor->id, 'subject' => 'first']] as $values) {
            try {
                DB::transaction(fn () => DB::table('user_sso_identities')->insert([...$values, 'issuer' => self::ISSUER]));
                $this->fail('Unique constraint required');
            } catch (QueryException $e) {
                $this->assertSame('23505', $e->getCode());
            }
        }
        try {
            DB::transaction(fn () => DB::table('users')->where('id', $this->target->id)->delete());
            $this->fail('Explicit unlink required');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
        $this->assertDatabaseHas('user_sso_identities', ['id' => $link->id]);
    }

    public function test_migration_rollback_is_safe_and_refuses_discarding_live_links(): void
    {
        $migration = require database_path('migrations/2026_10_20_000002_create_user_sso_identities.php');
        UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'first']);
        try {
            $migration->down();
            $this->fail('Live links must be preserved');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unlink', $e->getMessage());
        }
        DB::table('user_sso_identities')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('user_sso_identities'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('user_sso_identities'));
    }

    public function test_linked_user_can_still_use_local_login_without_contacting_identity_provider(): void
    {
        UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'first']);
        auth()->logout();
        $this->post(route('login.store'), ['email' => $this->target->email, 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/paz-salvos/consultar');
        $this->assertAuthenticatedAs($this->target);
        Http::assertNothingSent();
    }

    public function test_links_do_not_grant_administration_permission_or_restore_inactive_users(): void
    {
        UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'first']);
        $this->actingAs($this->target)->getJson($this->url())->assertForbidden();
        $this->target->update(['is_active' => false]);
        $this->actingAs($this->target)->getJson('/admin/sso-directory?query=directory')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    public function test_csrf_is_required_for_both_new_mutation_routes(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post($this->url(), $this->selection())->assertStatus(419);
        $this->delete($this->url(), ['identity_id' => 1, 'confirmed' => true])->assertStatus(419);
        $this->assertDatabaseCount('user_sso_identities', 0);
        Http::assertNothingSent();
    }

    public function test_audit_keeps_actor_identifier_if_the_administrator_is_later_deleted(): void
    {
        $this->directory();
        $this->post($this->url(), $this->selection())->assertSessionHasNoErrors();
        $actorId = $this->actor->id;
        $this->actor->delete();
        $this->assertNull($this->target->ssoIdentity()->sole()->created_by);
        $audit = AuditLog::where('event', 'user.sso.linked')->sole();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame($actorId, $audit->metadata['actor_user_id']);
    }

    public function test_user_deletion_requires_an_explicit_audited_unlink(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'first']);
        $before = $this->target->fresh()->getAttributes();
        $this->delete('/admin/users/'.$this->target->id)->assertSessionHasErrors('user');
        $this->assertSame($before, $this->target->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::where('event', 'user.deleted')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.destroy.failed', 'result' => 'failed']);
        $this->delete($this->url(), ['identity_id' => $link->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->delete('/admin/users/'.$this->target->id)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.sso.unlinked', 'result' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deleted', 'result' => 'success']);
        Http::assertNothingSent();
    }

    private function creation(array $changes = []): array
    {
        return array_replace(['name' => 'Explicit local user', 'email' => 'explicit-local@example.test',
            'agency_id' => $this->actor->agency_id, 'role' => 'operador', 'is_active' => true], $changes);
    }

    private function editing(array $changes = []): array
    {
        return array_replace(['name' => $this->target->name, 'email' => $this->target->email,
            'username' => $this->target->username, 'agency_id' => $this->target->agency_id,
            'role' => 'operador', 'is_active' => true, 'sso_identity_id' => null], $changes);
    }

    public function test_username_is_nullable_normalized_and_case_insensitively_unique(): void
    {
        $this->assertNull($this->target->fresh()->username);
        $this->post('/admin/users', $this->creation(['username' => '  Mi.Usuario  ']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'explicit-local@example.test', 'username' => 'mi.usuario']);
        $this->post('/admin/users', $this->creation(['email' => 'duplicate@example.test', 'username' => 'MI.USUARIO']))->assertSessionHasErrors('username');
        $this->assertDatabaseMissing('users', ['email' => 'duplicate@example.test']);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['username' => 'mI.UsUaRiO']))->assertSessionHasErrors('username');
        $this->target->username = '  J.PÉREZ ';
        $this->target->save();
        $this->assertSame('j.pérez', $this->target->fresh()->username);
        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname='users_username_ci_unique'");
        $this->assertStringContainsString('lower', $index->indexdef);
        $this->assertSame(0, AuditLog::where('event', 'user.sso.linked.failed')->count());
        Http::assertNothingSent();
    }

    public function test_profile_and_identity_are_replaced_together_using_fresh_server_verified_values(): void
    {
        $this->target->update(['username' => 'local.user']);
        $password = $this->target->password;
        $roles = $this->target->roles->modelKeys();
        $permissions = $this->target->permissions->modelKeys();
        $this->directory([$this->candidate(['name' => 'Institutional Person', 'email' => 'Person@aaud.gob.pa', 'username' => 'PERSON.AD', 'roles' => ['admin']])]);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'link', 'sso_identity' => $this->selection(),
            'username' => 'forged', 'name' => 'Forged', 'email' => 'forged@example.test']))->assertSessionHasNoErrors();
        $user = $this->target->fresh();
        $this->assertSame('person.ad', $user->username);
        $this->assertSame('Institutional Person', $user->name);
        $this->assertSame('person@aaud.gob.pa', $user->email);
        $this->assertSame($password, $user->password);
        $this->assertSame($roles, $user->roles->modelKeys());
        $this->assertSame($permissions, $user->permissions->modelKeys());
        $this->assertEquals(['username' => 'person.ad', 'name' => 'Institutional Person', 'email' => 'person@aaud.gob.pa'], $user->ssoIdentity->verified_profile);
        $audit = AuditLog::where('event', 'user.sso.profile.applied')->sole();
        $this->assertSame(['username', 'name', 'email'], $audit->metadata['replaced_fields']);
        $this->assertStringNotContainsString('Institutional Person', json_encode($audit->metadata));
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        Http::assertSentCount(2);
    }

    public function test_fallback_name_and_missing_email_preserve_local_values_and_provenance(): void
    {
        $this->directory([$this->candidate(['name' => 'directory-user', 'name_is_fallback' => true])]);
        $old = $this->target->only(['name', 'email']);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'link', 'sso_identity' => $this->selection()]))->assertSessionHasNoErrors();
        $this->assertSame($old, $this->target->fresh()->only(['name', 'email']));
        $this->assertSame(['username' => 'directory-user'], $this->target->fresh()->ssoIdentity->verified_profile);
    }

    public function test_invalid_directory_email_is_not_a_locked_institutional_field(): void
    {
        $this->directory([$this->candidate(['email' => 'invalid-email'])]);
        $this->getJson('/admin/sso-directory?query=directory')->assertOk()
            ->assertJsonPath('data.0.verified_profile.username', 'directory-user')->assertJsonMissingPath('data.0.verified_profile.email');
    }

    public function test_real_name_equal_to_username_is_not_misclassified_as_fallback(): void
    {
        $this->directory([$this->candidate(['name' => 'directory-user', 'name_is_fallback' => false])]);
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection()]))->assertSessionHasNoErrors();
        $user = User::where('email', 'explicit-local@example.test')->sole();
        $this->assertSame('directory-user', $user->name);
        $this->assertSame('directory-user', $user->ssoIdentity->verified_profile['name']);
    }

    public function test_saved_provenance_protects_verified_fields_from_forged_local_updates_without_remote_refresh(): void
    {
        $profile = ['username' => 'saved.user', 'name' => 'Saved Name', 'email' => 'saved@example.test'];
        $this->target->update($profile);
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'saved', 'verified_profile' => $profile]);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'keep', 'sso_identity_id' => $link->id,
            'name' => 'tamper', 'email' => 'tamper@example.test', 'username' => 'tamper']))->assertSessionHasNoErrors();
        $this->assertSame($profile, $this->target->fresh()->only(['username', 'name', 'email']));
        Http::assertNothingSent();
    }

    public function test_username_and_email_collisions_prevent_every_part_of_link_and_profile_update(): void
    {
        $this->actor->update(['username' => 'directory-user', 'email' => 'Reserved@aaud.gob.pa']);
        $before = $this->target->fresh()->getAttributes();
        $this->directory([$this->candidate(['email' => 'reserved@aaud.gob.pa'])]);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'link', 'sso_identity' => $this->selection()]))->assertSessionHasErrors('username');
        $this->actor->update(['username' => 'another']);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'link', 'sso_identity' => $this->selection()]))->assertSessionHasErrors('email');
        $this->assertSame($before, $this->target->fresh()->getAttributes());
        $this->assertDatabaseCount('user_sso_identities', 0);
    }

    public function test_unlink_and_local_changes_are_atomic_preserve_username_and_require_confirmation(): void
    {
        $this->target->update(['username' => 'saved.user']);
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'saved', 'verified_profile' => ['username' => 'saved.user']]);
        $password = $this->target->password;
        $permissions = $this->target->permissions->modelKeys();
        $data = $this->editing(['sso_action' => 'unlink', 'sso_identity_id' => $link->id, 'name' => 'Explicit edit', 'username' => null]);
        $this->put('/admin/users/'.$this->target->id, $data)->assertSessionHasErrors('identity');
        $this->assertSame($this->target->name, $this->target->fresh()->name);
        $this->put('/admin/users/'.$this->target->id, [...$data, 'sso_unlink_confirmed' => true])->assertRedirect();
        $user = $this->target->fresh();
        $this->assertSame('saved.user', $user->username);
        $this->assertSame('Explicit edit', $user->name);
        $this->assertSame($password, $user->password);
        $this->assertSame($permissions, $user->permissions->modelKeys());
        $this->assertTrue($user->hasRole('operador'));
        $this->assertDatabaseCount('user_sso_identities', 0);
        Http::assertNothingSent();
    }

    public function test_link_failure_rolls_back_profile_role_and_success_audits(): void
    {
        $this->directory();
        Role::create(['name' => 'consulta', 'guard_name' => 'web']);
        $before = $this->target->fresh()->getAttributes();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function ($event, $metadata = [], $subject = null, $request = null, $result = null) {
            if ($event === 'user.sso.linked') {
                throw new \RuntimeException('Injected failure');
            }

            return (new AuditLogger)->record($event, $metadata, $subject, $request, $result);
        });
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'link', 'sso_identity' => $this->selection(), 'role' => 'consulta']))->assertServerError();
        $this->assertSame($before, $this->target->fresh()->getAttributes());
        $this->assertTrue($this->target->fresh()->hasRole('operador'));
        $this->assertDatabaseCount('user_sso_identities', 0);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.updated', 'result' => 'success']);
    }

    public function test_unlink_audit_failure_rolls_back_the_profile_and_keeps_the_identity(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'saved']);
        $before = $this->target->fresh()->getAttributes();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function ($event, $metadata = [], $subject = null, $request = null, $result = null) {
            if ($event === 'user.sso.unlinked') {
                throw new \RuntimeException('Injected failure');
            }

            return (new AuditLogger)->record($event, $metadata, $subject, $request, $result);
        });
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'unlink', 'sso_identity_id' => $link->id,
            'sso_unlink_confirmed' => true, 'name' => 'Must roll back']))->assertServerError();
        $this->assertSame($before, $this->target->fresh()->getAttributes());
        $this->assertDatabaseHas('user_sso_identities', ['id' => $link->id]);
        Http::assertNothingSent();
    }

    public function test_stale_forms_cannot_link_or_unlink_a_different_saved_identity(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'saved']);
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'keep', 'name' => 'Stale edit']))->assertSessionHasErrors('identity');
        $this->assertSame($this->target->name, $this->target->fresh()->name);
        $this->assertDatabaseHas('user_sso_identities', ['id' => $link->id]);
    }

    public function test_legacy_link_can_be_explicitly_reverified_without_regenerating_its_identity_or_date(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'verified-subject']);
        $this->directory();
        $this->put('/admin/users/'.$this->target->id, $this->editing(['sso_action' => 'link', 'sso_identity_id' => $link->id, 'sso_identity' => $this->selection()]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('user_sso_identities', 1);
        $this->assertSame($link->created_at->toIso8601String(), $link->fresh()->created_at->toIso8601String());
        $this->assertSame('directory-user', $link->fresh()->verified_profile['username']);
    }

    public function test_new_migrations_rollback_and_reapply_without_removing_existing_users_or_links(): void
    {
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'saved']);
        $username = require database_path('migrations/2026_10_21_000001_add_username_to_users.php');
        $profile = require database_path('migrations/2026_10_21_000002_add_verified_profile_to_user_sso_identities.php');
        $profile->down();
        $username->down();
        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertFalse(Schema::hasColumn('user_sso_identities', 'verified_profile'));
        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
        $this->assertDatabaseHas('user_sso_identities', ['id' => $link->id]);
        $username->up();
        $profile->up();
        $this->assertNull($this->target->fresh()->username);
        $this->assertNull($link->fresh()->verified_profile);
    }

    public function test_creation_without_sso_preserves_local_password_role_and_state_without_http(): void
    {
        config(['aaud-auth.enabled' => false]);
        $this->post('/admin/users', $this->creation(['is_active' => false]))->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'explicit-local@example.test')->sole();
        $this->assertFalse($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check(config('security.temporary_user_password'), $user->password));
        $this->assertSame(['operador'], $user->getRoleNames()->all());
        $this->assertDatabaseCount('user_sso_identities', 0);
        Http::assertNothingSent();
    }

    public function test_creation_with_verified_identity_commits_user_role_link_and_audits_together(): void
    {
        $this->directory();
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection()]))
            ->assertSessionHasNoErrors()->assertSessionHas('message', 'Usuario creado e identidad SSO vinculada correctamente.');
        $user = User::where('email', 'explicit-local@example.test')->sole();
        $this->assertSame(['operador'], $user->getRoleNames()->all());
        $this->assertSame('verified-subject', $user->ssoIdentity()->sole()->subject);
        $this->assertSame($this->actor->id, $user->ssoIdentity()->sole()->created_by);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.created', 'subject_id' => $user->id, 'result' => 'success']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.sso.linked', 'subject_id' => $user->id, 'result' => 'success']);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        Http::assertSentCount(2);
    }

    public function test_failed_verification_creates_nothing_and_selection_can_be_removed_to_create_locally(): void
    {
        $this->directory([]);
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection()]))->assertSessionHasErrors('identity');
        $this->assertDatabaseMissing('users', ['email' => 'explicit-local@example.test']);
        $this->assertDatabaseCount('user_sso_identities', 0);
        $this->post('/admin/users', $this->creation())->assertRedirect();
        $this->assertSame(1, User::where('email', 'explicit-local@example.test')->count());
        $this->assertSame(0, AuditLog::where('event', 'user.sso.linked')->count());
    }

    public function test_disabled_directory_and_duplicate_identity_cannot_leave_partial_new_accounts(): void
    {
        config(['aaud-auth.enabled' => false]);
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection()]))->assertSessionHasErrors('identity');
        Http::assertNothingSent();
        $this->assertDatabaseMissing('users', ['email' => 'explicit-local@example.test']);
        config(['aaud-auth.enabled' => true]);
        $this->directory();
        UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'verified-subject']);
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection()]))->assertSessionHasErrors('identity');
        $this->assertDatabaseMissing('users', ['email' => 'explicit-local@example.test']);
        $this->assertSame(0, AuditLog::where('event', 'user.created')->count());
    }

    public function test_creation_requires_an_explicit_complete_sso_selection(): void
    {
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection(['confirmed' => false])]))->assertSessionHasErrors('sso_identity.confirmed');
        $this->post('/admin/users', $this->creation(['sso_identity' => ['subject' => 'arbitrary']]))->assertSessionHasErrors('sso_identity.issuer');
        $this->assertDatabaseMissing('users', ['email' => 'explicit-local@example.test']);
        Http::assertNothingSent();
    }

    public function test_sso_audit_failure_rolls_back_creation_role_and_prepared_signature_file(): void
    {
        $this->directory();
        Storage::fake(config('paz-salvo.disk'));
        Role::create(['name' => 'administrador_general', 'guard_name' => 'web']);
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function ($event, $metadata = [], $subject = null, $request = null, $result = null) {
            if ($event === 'user.sso.linked') {
                throw new \RuntimeException('SSO audit failure');
            }

            return (new AuditLogger)->record($event, $metadata, $subject, $request, $result);
        });
        $beforeRoles = DB::table('model_has_roles')->count();
        $this->post('/admin/users', $this->creation(['role' => 'administrador_general', 'sso_identity' => $this->selection(),
            'general_admin_signature' => UploadedFile::fake()->image('signature.png')]))->assertServerError();
        $this->assertDatabaseMissing('users', ['email' => 'explicit-local@example.test']);
        $this->assertDatabaseCount('user_sso_identities', 0);
        $this->assertDatabaseCount('general_admin_signatures', 0);
        $this->assertSame($beforeRoles, DB::table('model_has_roles')->count());
        $this->assertSame([], Storage::disk(config('paz-salvo.disk'))->allFiles());
        $this->assertSame(0, AuditLog::where('event', 'user.created')->where('result', 'success')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.store.failed', 'result' => 'failed']);
    }

    public function test_directory_verification_precedes_the_creation_transaction_and_locks(): void
    {
        $baseline = DB::transactionLevel();
        Http::fake(function ($request) use ($baseline) {
            $this->assertSame($baseline, DB::transactionLevel(), 'Verification must precede the operation transaction');
            $this->assertFalse(User::where('email', 'explicit-local@example.test')->exists());

            return str_ends_with($request->url(), '/token')
                ? Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300])
                : Http::response(['data' => [$this->candidate()], 'page' => 1, 'limit' => 20, 'has_more' => false]);
        });
        $this->post('/admin/users', $this->creation(['sso_identity' => $this->selection()]))->assertSessionHasNoErrors();
    }

    public function test_index_exposes_real_creation_and_link_timestamps_without_credentials(): void
    {
        $this->target->forceFill(['created_at' => '2026-10-19 14:15:00'])->save();
        $link = UserSsoIdentity::create(['user_id' => $this->target->id, 'issuer' => self::ISSUER, 'subject' => 'first', 'created_at' => '2026-10-20 15:35:00']);
        $expectedCreated = $this->target->fresh()->created_at->toIso8601String();
        $this->get('/admin/users')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/users/index')
            ->where('sso_directory_available', true)
            ->where('users', fn ($users) => collect($users)->contains(fn ($user) => $user['id'] === $this->target->id
                && $user['created_at'] === $expectedCreated && $user['sso_linked'] === true
                && $user['sso_identity']['linked_at'] === $link->created_at->toIso8601String()
                && ! isset($user['password']) && ! isset($user['client_secret']))));
        Http::assertNothingSent();
    }
}
