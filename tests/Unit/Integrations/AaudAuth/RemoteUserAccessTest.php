<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Access\AccessSnapshot;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Integrations\AaudAuth\Http\RemoteUserAccess;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NoDatabaseIntegrationTestCase;

class RemoteUserAccessTest extends NoDatabaseIntegrationTestCase
{
    private function snapshot(): AccessSnapshot
    {
        config(['aaud-auth.issuer' => 'https://id.example.test/realms/aaud']);

        return AccessSnapshot::fromArray(['issuer' => config('aaud-auth.issuer'), 'subject' => 'subject-A', 'application_user_id' => '15', 'is_active' => false, 'roles' => []]);
    }

    private function absent(): array
    {
        return ['issuer' => $this->snapshot()->issuer, 'subject' => 'subject-A', 'application_code' => 'pazysalvo',
            'application_user_id' => null, 'exists' => false, 'is_active' => false, 'roles' => [], 'version' => 0];
    }

    public static function accessCases(): array
    {
        return ['absent' => [false], 'existing' => [true]];
    }

    #[DataProvider('accessCases')]
    public function test_wrapped_access_and_fixed_authenticated_get_without_redirects(bool $exists): void
    {
        $snapshot = $this->snapshot();
        $data = $exists ? array_replace($this->absent(), ['exists' => true, 'application_user_id' => '15', 'is_active' => true, 'roles' => ['operador'], 'version' => 7]) : $this->absent();
        config(['aaud-auth.enabled' => true, 'aaud-auth.api_url' => 'https://auth.example.test', 'aaud-auth.token_url' => 'https://id.example.test/token',
            'aaud-auth.client_id' => 'pazysalvo-sync', 'aaud-auth.client_secret' => 'private-fixture', 'aaud-auth.connect_timeout' => 5,
            'aaud-auth.timeout' => 10, 'aaud-auth.verify_tls' => true, 'aaud-auth.token_expiry_leeway' => 30]);
        Http::fake(['https://id.example.test/token' => Http::response(['access_token' => 'private-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'access.write']),
            'https://auth.example.test/*' => function ($request, $options) use ($data) {
                $this->assertFalse($options['allow_redirects']);
                $this->assertTrue($options['verify']);
                $this->assertSame('GET', $request->method());
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer private-token'));
                $this->assertSame($this->snapshot()->issuer, $request['issuer']);

                return Http::response(['data' => $data]);
            }]);
        $result = app(AaudAuthClient::class)->getUserAccess($snapshot);
        $this->assertSame($data, $result->data);
        $this->assertSame($exists, $result->data['exists']);
        $this->assertSame($exists ? 7 : 0, $result->data['version']);
        $this->assertSame($exists, $result->snapshotFor('15')->isActive);
        Http::assertSentCount(2);
    }

    public static function invalid(): array
    {
        return [[['issuer' => 'https://other.test/realms/aaud']], [['subject' => 'other']], [['application_code' => 'other']],
            [['version' => '0']], [['version' => 1]], [['exists' => 0]], [['application_user_id' => '15']],
            [['is_active' => true]], [['roles' => ['operador']]], [['secret' => 'forbidden']],
            [['exists' => true, 'application_user_id' => '15', 'version' => 1, 'is_active' => true, 'roles' => ['operador', 'operador']]]];
    }

    #[DataProvider('invalid')]
    public function test_rejects_inconsistent_or_wrong_identity_evidence(array $change): void
    {
        $data = array_replace($this->absent(), $change);
        $this->expectException(AaudAuthRequestException::class);
        RemoteUserAccess::fromResponse($data, $this->snapshot());
    }

    public function test_another_local_owner_never_implicitly_transfers_access(): void
    {
        $result = RemoteUserAccess::fromResponse(array_replace($this->absent(), ['exists' => true, 'application_user_id' => '99', 'version' => 7, 'is_active' => true, 'roles' => ['operador']]), $this->snapshot());
        $this->expectException(AaudAuthRequestException::class);
        $result->snapshotFor('15');
    }

    public static function getErrors(): array
    {
        $valid = ['issuer' => 'https://id.example.test/realms/aaud', 'subject' => 'subject-A', 'application_code' => 'pazysalvo',
            'application_user_id' => null, 'exists' => false, 'is_active' => false, 'roles' => [], 'version' => 0];

        return [[401, ['error' => ['code' => 'invalid_service_token']], 'unauthorized'],
            [403, [], 'forbidden'], [404, [], 'not_found'], [409, ['error' => ['code' => 'stale_version']], 'version_conflict'],
            [422, [], 'invalid_request'], [429, [], 'rate_limited'], [503, [], 'temporary'], [302, [], 'unexpected_response'],
            [200, '{invalid', 'invalid_response'], [200, ['exists' => false], 'invalid_response'],
            [200, $valid, 'invalid_response'], [200, [], 'invalid_response'], [200, 'null', 'invalid_response'],
            [200, ['data' => null], 'invalid_response'], [200, ['data' => 'invalid'], 'invalid_response'],
            [200, ['data' => 1], 'invalid_response'], [200, ['data' => false], 'invalid_response'],
            [200, ['data' => []], 'invalid_response'], [200, ['data' => (object) []], 'invalid_response'],
            [200, ['data' => [$valid]], 'invalid_response'], [200, ['data' => ['exists' => false]], 'invalid_response'],
            [200, ['error' => ['code' => 'request_rejected'], 'data' => $valid], 'invalid_response'],
            [200, ['error' => null, 'data' => $valid], 'invalid_response'],
            [200, ['data' => array_replace($valid, ['issuer' => 'https://other.test/realms/aaud'])], 'invalid_response'],
            [200, ['data' => array_replace($valid, ['version' => '0'])], 'invalid_response'],
            [200, ['data' => array_replace($valid, ['subject' => 'other'])], 'invalid_response']];
    }

    #[DataProvider('getErrors')]
    public function test_get_errors_never_become_proof_of_absence(int $status, mixed $body, string $reason): void
    {
        $snapshot = $this->snapshot();
        config(['aaud-auth.enabled' => true, 'aaud-auth.api_url' => 'https://auth.example.test', 'aaud-auth.token_url' => 'https://id.example.test/token',
            'aaud-auth.client_id' => 'pazysalvo-sync', 'aaud-auth.client_secret' => 'private-fixture', 'aaud-auth.connect_timeout' => 5,
            'aaud-auth.timeout' => 10, 'aaud-auth.verify_tls' => true, 'aaud-auth.token_expiry_leeway' => 30]);
        Http::fake(['https://id.example.test/token' => Http::response(['access_token' => 'private-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'access.write']),
            'https://auth.example.test/*' => Http::response($body, $status)]);
        try {
            app(AaudAuthClient::class)->getUserAccess($snapshot);
            $this->fail('GET must reject unverifiable evidence');
        } catch (AaudAuthRequestException $failure) {
            $this->assertSame($reason, $failure->reason);
            $this->assertNull($failure->getPrevious());
            $this->assertStringNotContainsString('private-token', (string) $failure);
            $this->assertStringNotContainsString('private-fixture', (string) $failure);
        }
    }
}
