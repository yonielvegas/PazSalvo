<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Integrations\AaudAuth\Keycloak\TechnicalTokenProvider;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NoDatabaseIntegrationTestCase;

class UserAccessClientTest extends NoDatabaseIntegrationTestCase
{
    private const ISSUER = 'https://keycloak.example.test/realms/aaud';

    private const TOKEN_URL = self::ISSUER.'/protocol/openid-connect/token';

    private const API = 'https://auth.example.test/api/integrations/v1/applications/pazysalvo/users/subject-A/access';

    private const TOKEN = 'fixture.technical.token';

    private const SECRET = 'fixture-secret-never-log';

    private const OPERATION = 'a0000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['aaud-auth' => ['enabled' => true, 'api_url' => 'https://auth.example.test', 'issuer' => self::ISSUER,
            'token_url' => self::TOKEN_URL, 'client_id' => 'pazysalvo-sync', 'client_secret' => self::SECRET,
            'connect_timeout' => 5, 'timeout' => 10, 'verify_tls' => true, 'ca_bundle' => null, 'token_expiry_leeway' => 30]]);
    }

    private function operation(array $changes = []): array
    {
        return ['issuer' => self::ISSUER, 'subject' => 'subject-A', 'application_user_id' => '15', 'is_active' => true,
            'roles' => ['operador'], 'version' => 7, 'operation_id' => self::OPERATION, ...$changes];
    }

    /** Contract fixture derived from AAUD AUTH ApplicationManagedAccess::receipt/duplicate. */
    private function receipt(string $status = 'applied', int $current = 7, ?array $stateChanges = null): array
    {
        $body = ['status' => $status, 'data' => [...$this->operation(), 'application_code' => 'pazysalvo', 'confirmed_at' => '2026-10-21T09:00:00-05:00'],
            'current_version' => $current, 'historical' => $current > 7];
        if ($status === 'duplicate') {
            $body['current_state'] = ['version' => $current, 'is_active' => true, 'roles' => ['operador'], 'catalog_version' => 4, ...($stateChanges ?? [])];
            $body['historical'] = $current > 7 || $body['current_state']['is_active'] !== true || $body['current_state']['roles'] !== ['operador'];
        }

        return $body;
    }

    private function fake(mixed $body, int $status = 200, array $headers = []): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'access.write']),
            self::API => is_callable($body) || $body instanceof PromiseInterface ? $body : Http::response($body, $status, $headers)]);
    }

    private function failure(array $operation, string $reason, ?AaudAuthClient $client = null): AaudAuthRequestException
    {
        try {
            ($client ?? app(AaudAuthClient::class))->syncUserAccess($operation);
            $this->fail('Expected a classified failure');
        } catch (AaudAuthRequestException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::SECRET, (string) $exception);
            $this->assertStringNotContainsString(self::TOKEN, (string) $exception);
            foreach ($exception->getTrace() as $frame) {
                $this->assertStringNotContainsString(self::SECRET, print_r($frame['args'] ?? [], true));
            }

            return $exception;
        }
    }

    public function test_put_exact_payload_fixed_application_technical_scope_tls_and_no_database_writes(): void
    {
        config(['aaud-auth.ca_bundle' => __FILE__]);
        $this->fake(function ($request, $options) {
            $this->assertSame(__FILE__, $options['verify']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['http_errors']);

            return Http::response($this->receipt());
        });
        $result = app(AaudAuthClient::class)->syncUserAccess($this->operation());
        $this->assertTrue($result->wasApplied());
        $this->assertFalse($result->isDuplicate());
        $this->assertTrue($result->confirmsCurrentAccess());
        $this->assertSame('pazysalvo', $result->snapshot->applicationCode);
        $payload = $this->operation();
        unset($payload['subject']);
        Http::assertSent(fn ($request) => $request->url() === self::API && $request->method() === 'PUT'
            && $request->data() === $payload && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('Content-Type', 'application/json'));
        Http::assertSent(fn ($request) => $request->url() === self::TOKEN_URL && $request->method() === 'POST'
            && $request['client_id'] === 'pazysalvo-sync' && $request['scope'] === 'access.write');
        Http::assertSentCount(2);
    }

    public static function recognisedReceipts(): array
    {
        return ['duplicate current' => [7, [], false], 'historical newer version' => [8, [], true],
            'historical catalog reconciliation at same version' => [7, ['is_active' => false, 'roles' => []], true],
            'historical different roles same version' => [7, ['roles' => ['consulta']], true]];
    }

    #[DataProvider('recognisedReceipts')]
    public function test_duplicate_recognition_does_not_imply_current_state_confirmation(int $current, array $changes, bool $historical): void
    {
        $this->fake($this->receipt('duplicate', $current, $changes));
        $result = app(AaudAuthClient::class)->syncUserAccess($this->operation());
        $this->assertTrue($result->isDuplicate());
        $this->assertFalse($result->wasApplied());
        $this->assertSame($historical, $result->historical);
        $this->assertSame(! $historical, $result->confirmsCurrentAccess());
        $this->assertSame($current, $result->currentVersion);
        $this->assertSame('15', $result->snapshot->applicationUserId);
    }

    public function test_explicit_revocation_put_uses_empty_roles(): void
    {
        $operation = $this->operation(['is_active' => false, 'roles' => []]);
        $body = $this->receipt();
        $body['data']['is_active'] = false;
        $body['data']['roles'] = [];
        $this->fake($body);
        $result = app(AaudAuthClient::class)->syncUserAccess($operation);
        $this->assertTrue($result->confirmsCurrentAccess());
        $this->assertFalse($result->snapshot->isActive);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['is_active'] === false && $request['roles'] === []);
    }

    public static function mismatches(): array
    {
        return [['issuer', self::ISSUER.'/other'], ['subject', 'subject-B'], ['application_code', 'other'],
            ['application_user_id', '20'], ['is_active', false], ['roles', ['consulta']], ['roles', ['operador', 'operador']],
            ['version', 8], ['version', '7'], ['operation_id', 'b0000000-0000-4000-8000-000000000001'],
            ['confirmed_at', null], ['confirmed_at', 'invalid'], ['confirmed_at', "bad\0timestamp"], ['confirmed_at', '2026-02-31T00:00:00+00:00']];
    }

    #[DataProvider('mismatches')]
    public function test_other_or_incomplete_receipts_are_uncertain(string $field, mixed $value): void
    {
        $body = $this->receipt();
        $body['data'][$field] = $value;
        $this->fake($body);
        $this->assertTrue($this->failure($this->operation(), 'invalid_response')->isDeliveryUncertain());
    }

    public static function invalidOperations(): array
    {
        return [['issuer', ''], ['issuer', self::ISSUER.'/'], ['subject', '../A'], ['subject', ''],
            ['application_user_id', 15], ['application_user_id', '0'], ['is_active', 1], ['roles', []], ['roles', ['ADMIN']],
            ['roles', ['operador', 'consulta']], ['version', 0], ['version', '7'], ['version', 9007199254740992],
            ['operation_id', ''], ['operation_id', null], ['application_code', 'other'], ['password', 'never-send'], ['client_id', 'other']];
    }

    #[DataProvider('invalidOperations')]
    public function test_invalid_operations_fail_before_token_and_never_generate_envelope(string $field, mixed $value): void
    {
        $this->failure($this->operation([$field => $value]), 'invalid_operation');
        Http::assertNothingSent();
    }

    public function test_missing_version_or_uuid_is_not_generated(): void
    {
        foreach (['version', 'operation_id'] as $field) {
            $operation = $this->operation();
            unset($operation[$field]);
            $this->failure($operation, 'invalid_operation');
        }
        Http::assertNothingSent();
    }

    public static function rejections(): array
    {
        return [[401, 'invalid_service_token', 'unauthorized'], [403, 'application_mismatch', 'forbidden'],
            [404, 'institutional_identity_not_found', 'not_found'], [404, 'application_access_not_found', 'not_found'],
            [409, 'stale_version', 'version_conflict'], [409, 'operation_conflict', 'idempotency_conflict'],
            [409, 'application_identity_conflict', 'conflict'], [422, 'roles_invalid_or_inactive', 'invalid_request'],
            [429, 'rate_limited', 'rate_limited'], [503, 'directory_unavailable', 'temporary']];
    }

    #[DataProvider('rejections')]
    public function test_receiver_errors_are_sanitized_and_classified(int $status, string $code, string $reason): void
    {
        $this->fake(['error' => ['code' => $code, 'message' => self::SECRET, 'token' => self::TOKEN]], $status, ['Retry-After' => '12']);
        $failure = $this->failure($this->operation(), $reason);
        $this->assertSame($code, $failure->remoteCode);
        $this->assertSame($status, $failure->httpStatus);
        $this->assertSame(12, $failure->retryAfterSeconds);
        $this->assertSame($status >= 500, $failure->isDeliveryUncertain());
        $this->assertSame($status < 500, $failure->isDefinitiveRejection());
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
        Http::assertSentCount(2);
    }

    public function test_timeout_lost_response_can_only_retry_exact_same_external_operation(): void
    {
        $client = app(AaudAuthClient::class);
        $original = $this->operation();
        $calls = [];
        $this->fake(function ($request) use (&$calls) {
            $calls[] = $request->body();
            if (count($calls) === 1) {
                throw new ConnectionException('timeout after remote commit '.self::SECRET);
            }

            return Http::response($this->receipt('duplicate'));
        });
        $failure = $this->failure($original, 'timeout', $client);
        $this->assertTrue($failure->isDeliveryUncertain());
        $this->assertFalse($failure->isDefinitiveRejection());
        $this->assertCount(1, $calls); // No automatic retry.
        $result = $client->syncUserAccess($original);
        $this->assertTrue($result->isDuplicate());
        $this->assertSame([$calls[0], $calls[0]], $calls);
        $this->assertSame($original, $this->operation());
        $this->assertSame(self::OPERATION, $result->operationId);
        $this->assertSame(7, $result->version);
        Http::assertSentCount(2); // HTTP fake records token + successful PUT; the failed attempt is tracked by $calls.
    }

    public function test_connection_failure_is_uncertain_and_never_propagates_transport_body(): void
    {
        $this->fake(fn () => throw new ConnectionException('connection lost '.self::SECRET));
        $this->assertTrue($this->failure($this->operation(), 'connection')->isDeliveryUncertain());
    }

    public function test_malformed_json_missing_fields_and_inconsistent_historical_flags_are_not_confirmed(): void
    {
        $invalid = ['{invalid', 'null', [], ['status' => 'applied'], [...$this->receipt(), 'historical' => true],
            [...$this->receipt(), 'current_version' => 8], [...$this->receipt('duplicate'), 'current_state' => null],
            [...$this->receipt('duplicate', 7, ['is_active' => false, 'roles' => []]), 'historical' => false],
            [...$this->receipt('duplicate'), 'historical' => true]];
        // One fake callback and a queue avoid replacing the HTTP factory or its stray-request guard.
        $this->fake(function () use (&$invalid) {
            return Http::response(array_shift($invalid));
        });
        $client = app(AaudAuthClient::class);
        while ($invalid !== []) {
            $this->assertTrue($this->failure($this->operation(), 'invalid_response', $client)->isDeliveryUncertain());
        }
    }

    public function test_default_token_cache_is_not_reused_for_a_different_required_scope(): void
    {
        $requestedScopes = [];
        Http::fake([self::TOKEN_URL => function ($request) use (&$requestedScopes) {
            $requestedScopes[] = $request['scope'] ?? null;

            return Http::response(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 300,
                'scope' => $request['scope'] ?? 'roles.write directory.read']);
        }, self::API => Http::response($this->receipt())]);
        $provider = app(TechnicalTokenProvider::class);
        $provider->getToken();
        $client = new AaudAuthClient($provider);
        $client->syncUserAccess($this->operation());
        $this->assertSame([null, 'access.write'], $requestedScopes);
    }

    public function test_missing_granted_access_scope_prevents_the_put(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'roles.write'])]);
        try {
            app(AaudAuthClient::class)->syncUserAccess($this->operation());
            $this->fail('Missing access.write must fail before PUT');
        } catch (TechnicalAuthenticationException $exception) {
            $this->assertSame(TechnicalAuthenticationException::REJECTED, $exception->reason);
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }
}
