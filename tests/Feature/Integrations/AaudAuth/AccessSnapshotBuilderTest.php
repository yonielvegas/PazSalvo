<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Access\AccessSnapshotBuilder;
use App\Integrations\AaudAuth\Keycloak\TechnicalTokenProvider;
use App\Models\Role;
use App\Models\User;
use App\Models\UserSsoIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessSnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://keycloak.example.test/realms/aaud';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-auth.issuer' => self::ISSUER]);
        Http::preventStrayRequests();
        $this->app->bind(TechnicalTokenProvider::class, fn () => throw new \LogicException('Builder must not obtain a token'));
    }

    private function user(): User
    {
        $role = Role::create(['name' => 'Nombre visible', 'code' => 'operador', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        UserSsoIdentity::create(['user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => 'subject-A']);

        return $user;
    }

    public function test_real_user_snapshot_reads_current_links_and_roles_without_writing_anything(): void
    {
        $user = $this->user();
        $tables = ['users', 'roles', 'model_has_roles', 'user_sso_identities', 'audit_logs', 'aaud_auth_catalog_states', 'aaud_auth_catalog_operations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $snapshot = app(AccessSnapshotBuilder::class)->build($user);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query));
        }
        $this->assertSame((string) $user->id, $snapshot->applicationUserId);
        $this->assertSame(['operador'], $snapshot->roles);
        $this->assertTrue($snapshot->isActive);
        foreach ($before as $table => $data) {
            $this->assertSame($data, DB::table($table)->get()->toJson());
        }
        Http::assertNothingSent();
    }

    public function test_local_login_block_does_not_revoke_but_administrative_deactivation_does(): void
    {
        $user = $this->user();
        $user->update(['is_login_blocked' => true, 'login_attempts' => 10]);
        $this->assertTrue(app(AccessSnapshotBuilder::class)->build($user)->isActive);
        $user->update(['is_active' => false]);
        $snapshot = app(AccessSnapshotBuilder::class)->build($user);
        $this->assertFalse($snapshot->isActive);
        $this->assertSame([], $snapshot->roles);
        Http::assertNothingSent();
    }

    public function test_deleted_link_can_be_revoked_with_old_snapshot_and_never_uses_new_link(): void
    {
        $user = $this->user();
        $builder = app(AccessSnapshotBuilder::class);
        $old = $builder->build($user)->payload();
        $user->load('ssoIdentity', 'roles');
        $user->ssoIdentity()->delete();
        $this->assertNull($builder->build($user)); // Even when the caller retains a cached relationship.
        UserSsoIdentity::create(['user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => 'subject-B']);
        $this->assertSame('subject-B', $builder->build($user)->subject);
        $revocation = $builder->revocation($old);
        $this->assertSame('subject-A', $revocation->subject);
        $this->assertFalse($revocation->isActive);
        $this->assertSame([], $revocation->roles);
        $this->assertDatabaseHas('user_sso_identities', ['user_id' => $user->id, 'subject' => 'subject-B']);
        Http::assertNothingSent();
    }

    public function test_unlinked_local_user_never_generates_federated_access(): void
    {
        $user = User::factory()->create(['email' => 'institutional@example.test']);
        $this->assertNull(app(AccessSnapshotBuilder::class)->build($user));
        $this->assertDatabaseCount('user_sso_identities', 0);
        Http::assertNothingSent();
    }
}
