<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Access\AccessSnapshotBuilder;
use App\Integrations\AaudAuth\Exceptions\InvalidAccessSnapshotException;
use App\Models\Role;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NoDatabaseIntegrationTestCase;

class AccessSnapshotBuilderTest extends NoDatabaseIntegrationTestCase
{
    private const ISSUER = 'https://keycloak.example.test/realms/aaud';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-auth.issuer' => self::ISSUER]);
        Http::preventStrayRequests();
    }

    private function role(array $changes = []): Role
    {
        return (new Role)->setRawAttributes(['guard_name' => 'web', 'is_active' => true, 'code' => 'operador', ...$changes], true);
    }

    public function test_pure_transformation_has_no_queries_http_or_version_generation(): void
    {
        $role = $this->role();
        $before = $role->getAttributes();
        $snapshot = (new AccessSnapshotBuilder)->fromState('15', true, ['issuer' => self::ISSUER, 'subject' => 'subject-A'], [$role]);
        $this->assertSame('pazysalvo', $snapshot->applicationCode);
        $this->assertSame(['issuer' => self::ISSUER, 'subject' => 'subject-A', 'application_user_id' => '15', 'is_active' => true, 'roles' => ['operador']], $snapshot->payload());
        $this->assertSame($before, $role->getAttributes());
        $this->assertArrayNotHasKey('version', $snapshot->payload());
        $this->assertArrayNotHasKey('operation_id', $snapshot->payload());
        Http::assertNothingSent();
    }

    public function test_unlinked_user_has_no_snapshot_and_inactive_link_does_not_require_roles(): void
    {
        $builder = new AccessSnapshotBuilder;
        $this->assertNull($builder->fromState('15', true, null, []));
        $snapshot = $builder->fromState('15', false, ['issuer' => self::ISSUER, 'subject' => 'subject-A'], []);
        $this->assertFalse($snapshot->isActive);
        $this->assertSame([], $snapshot->roles);
    }

    public static function inconsistentStates(): array
    {
        $issuer = self::ISSUER;

        return [
            'no role' => [true, ['issuer' => $issuer, 'subject' => 'A'], [], 'invalid_state'],
            'multiple roles' => [true, ['issuer' => $issuer, 'subject' => 'A'], [[], ['code' => 'consulta']], 'invalid_state'],
            'wrong guard' => [true, ['issuer' => $issuer, 'subject' => 'A'], [['guard_name' => 'api']], 'invalid_role_guard'],
            'inactive role' => [true, ['issuer' => $issuer, 'subject' => 'A'], [['is_active' => false]], 'inactive_role'],
            'malformed state' => ['false', ['issuer' => $issuer, 'subject' => 'A'], [[]], 'invalid_administrative_state'],
            'invalid role code' => [true, ['issuer' => $issuer, 'subject' => 'A'], [['code' => 'ADMIN']], 'invalid_role_code'],
            'empty issuer' => [false, ['issuer' => '', 'subject' => 'A'], [], 'invalid_issuer'],
            'wrong issuer' => [false, ['issuer' => $issuer.'/', 'subject' => 'A'], [], 'invalid_issuer'],
            'empty subject' => [false, ['issuer' => $issuer, 'subject' => ''], [], 'invalid_subject'],
            'invalid subject' => [false, ['issuer' => $issuer, 'subject' => '../A'], [], 'invalid_subject'],
            'incomplete identity' => [false, ['issuer' => $issuer], [], 'invalid_subject'],
        ];
    }

    #[DataProvider('inconsistentStates')]
    public function test_inconsistencies_are_rejected(mixed $active, array $identity, array $roleAttributes, string $reason): void
    {
        try {
            (new AccessSnapshotBuilder)->fromState('15', $active, $identity, array_map(fn ($attributes) => $this->role($attributes), $roleAttributes));
            $this->fail('Inconsistent state must be rejected');
        } catch (InvalidAccessSnapshotException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function test_explicit_previous_association_builds_revocation_without_loading_a_link(): void
    {
        $snapshot = (new AccessSnapshotBuilder)->revocation(['application_user_id' => '15', 'issuer' => self::ISSUER, 'subject' => 'old-subject']);
        $this->assertFalse($snapshot->isActive);
        $this->assertSame([], $snapshot->roles);
        $this->assertSame('old-subject', $snapshot->subject);
        $this->assertSame('15', $snapshot->applicationUserId);
    }

    public function test_previous_association_cannot_select_another_application(): void
    {
        $this->expectException(InvalidAccessSnapshotException::class);
        (new AccessSnapshotBuilder)->revocation(['application_code' => 'other', 'application_user_id' => '15', 'issuer' => self::ISSUER, 'subject' => 'old']);
    }
}
