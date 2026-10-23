<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AaudAuthClientTest extends TestCase
{
    private const TOKEN_URL = 'https://keycloak.example.test/realms/aaud/protocol/openid-connect/token';

    private const API = 'https://auth.example.test/api/integrations/v1/applications/pazysalvo/roles';

    private const TOKEN = 'fixture.technical.token';

    private const SECRET = 'private-fixture-secret';

    private const OPERATION = 'a0000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['aaud-auth' => [
            'enabled' => true, 'api_url' => 'https://auth.example.test/', 'token_url' => self::TOKEN_URL,
            'client_id' => 'pazysalvo-sync', 'client_secret' => self::SECRET, 'connect_timeout' => 5,
            'timeout' => 10, 'verify_tls' => true, 'ca_bundle' => null, 'token_expiry_leeway' => 30,
        ]]);
    }

    private function roles(): array
    {
        return [['code' => 'admin', 'name' => 'admin', 'active' => true], ['code' => 'operador', 'name' => 'Operador', 'active' => false]];
    }

    /** Mirrors IntegrationController::catalog and ApplicationManagedAccess::receipt/duplicate. */
    private function response(array $roles, string $status = 'applied', int $version = 7, ?int $current = null, string $operation = self::OPERATION): array
    {
        $roles = array_map(fn ($role) => ['code' => $role['code'], 'name' => trim($role['name']), 'active' => $role['active'], 'description' => null], $roles);
        usort($roles, fn ($a, $b) => strcmp($a['code'], $b['code']));
        $current ??= $version;
        $body = ['status' => $status,
            'data' => ['application_code' => 'pazysalvo', 'version' => $version, 'operation_id' => strtolower($operation), 'roles' => $roles],
            'current_version' => $current, 'historical' => $current > $version];
        if ($status === 'duplicate') {
            $body['current_state'] = ['catalog_version' => $current];
        }

        return $body;
    }

    private function fake(mixed $apiResponse, int $status = 200, array $headers = []): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 300]),
            self::API => is_callable($apiResponse) || $apiResponse instanceof PromiseInterface
                ? $apiResponse : Http::response($apiResponse, $status, $headers),
        ]);
    }

    private function failure(string $reason, ?int $status = null, ?AaudAuthClient $client = null, ?array $roles = null, int $version = 7, string $operation = self::OPERATION): AaudAuthRequestException
    {
        try {
            ($client ?? app(AaudAuthClient::class))->syncRoleCatalog($version, $operation, $roles ?? $this->roles());
            $this->fail('Expected a safe AAUD AUTH error');
        } catch (AaudAuthRequestException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($status, $exception->httpStatus);
            $this->assertNull($exception->getPrevious());
            $diagnostic = (string) $exception;
            $this->assertStringNotContainsString(self::TOKEN, $diagnostic);
            $this->assertStringNotContainsString(self::SECRET, $diagnostic);
            foreach ($exception->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'App\\Integrations\\AaudAuth\\')) {
                    $args = print_r($frame['args'] ?? [], true);
                    $this->assertStringNotContainsString(self::TOKEN, $args);
                    $this->assertStringNotContainsString(self::SECRET, $args);
                }
            }

            return $exception;
        }
    }

    public function test_transport_uses_technical_provider_json_bearer_and_fixed_url_without_side_effects(): void
    {
        $this->fake($this->response($this->roles()));
        $client = app(AaudAuthClient::class);
        Http::assertNothingSent();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $result = $client->syncRoleCatalog(7, self::OPERATION, $this->roles());
        $this->assertSame([], $queries);
        $this->assertSame('applied', $result->status);
        $this->assertTrue($result->confirmsCurrentCatalog());
        $this->assertSame(7, $result->version);
        $this->assertSame(self::OPERATION, $result->operationId);
        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'client_credentials' && $request['client_id'] === 'pazysalvo-sync');
        Http::assertSent(fn (Request $request) => $request->url() === self::API && $request->method() === 'PUT'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $request->hasHeader('Content-Type', 'application/json') && $request->hasHeader('Accept', 'application/json')
            && $request->data() === ['version' => 7, 'operation_id' => self::OPERATION, 'roles' => $this->roles()]
            && ! isset($request['client_secret']));
        Http::assertSentCount(2);
    }

    public function test_timeouts_redirects_and_tls_are_preserved(): void
    {
        config(['aaud-auth.ca_bundle' => __FILE__]);
        $this->fake(function (Request $request, array $options) {
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertSame(__FILE__, $options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['http_errors']);

            return Http::response($this->response($this->roles()));
        });
        app(AaudAuthClient::class)->syncRoleCatalog(7, self::OPERATION, $this->roles());
    }

    public function test_disabled_client_never_calls_token_provider(): void
    {
        config(['aaud-auth.enabled' => false]);
        $this->failure('disabled');
        Http::assertNothingSent();
    }

    public static function invalidConfigurations(): array
    {
        return [['api_url', null], ['api_url', 'invalid'], ['api_url', 'ftp://localhost'],
            ['api_url', 'http://external.example.test'], ['api_url', 'https://user:pass@auth.example.test'],
            ['api_url', 'https://auth.example.test?x=1'], ['api_url', 'https://auth.example.test#fragment'],
            ['api_url', 'https://auth.example.test/another/path'], ['connect_timeout', 0], ['connect_timeout', true],
            ['timeout', 1], ['timeout', 'invalid'], ['verify_tls', false], ['ca_bundle', '/missing/ca.pem']];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_transport_configuration_never_requests_a_token(string $key, mixed $value): void
    {
        config(['aaud-auth.'.$key => $value]);
        $this->failure('configuration');
        Http::assertNothingSent();
    }

    public function test_http_loopback_is_explicitly_local_and_rejected_in_production(): void
    {
        config(['aaud-auth.api_url' => 'http://localhost:18000']);
        Http::fake([
            self::TOKEN_URL => Http::response(['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 300]),
            'http://localhost:18000/api/integrations/v1/applications/pazysalvo/roles' => Http::response($this->response($this->roles())),
        ]);
        $this->assertTrue(app(AaudAuthClient::class)->syncRoleCatalog(7, self::OPERATION, $this->roles())->confirmsCurrentCatalog());
        config(['app.env' => 'production']);
        $this->failure('configuration');
        Http::assertSentCount(2);
    }

    public function test_retry_is_exact_and_preserves_uuid_case_version_and_body(): void
    {
        $operation = strtoupper(self::OPERATION);
        $roles = array_reverse($this->roles());
        $roles[0]['name'] = ' Operador ';
        $this->fake(Http::sequence()->push($this->response($roles, operation: $operation))->push($this->response($roles, 'duplicate', operation: $operation)));
        $client = app(AaudAuthClient::class);
        $first = $client->syncRoleCatalog(7, $operation, $roles);
        $second = $client->syncRoleCatalog(7, $operation, $roles);
        $this->assertSame('applied', $first->status);
        $this->assertSame('duplicate', $second->status);
        $this->assertTrue($second->confirmsCurrentCatalog());
        $puts = Http::recorded(fn ($request) => $request->method() === 'PUT')->values();
        $this->assertCount(2, $puts);
        $this->assertSame($puts[0][0]->body(), $puts[1][0]->body());
        $this->assertSame(['version' => 7, 'operation_id' => $operation, 'roles' => $roles], $puts[0][0]->data());
        Http::assertSentCount(3); // one token, two explicit calls; no automatic retries
    }

    public function test_historical_duplicate_confirms_only_the_old_operation(): void
    {
        $this->fake($this->response($this->roles(), 'duplicate', current: 9));
        $result = app(AaudAuthClient::class)->syncRoleCatalog(7, self::OPERATION, $this->roles());
        $this->assertTrue($result->historical);
        $this->assertSame(9, $result->currentVersion);
        $this->assertFalse($result->confirmsCurrentCatalog());
    }

    public static function errors(): array
    {
        return [[401, 'invalid_service_token', 'unauthorized'], [403, 'client_not_authorized', 'forbidden'],
            [409, 'stale_version', 'version_conflict'], [409, 'operation_conflict', 'idempotency_conflict'],
            [409, 'operation_or_identity_conflict', 'conflict'], [422, 'validation_failed', 'invalid_request'],
            [429, 'rate_limited', 'rate_limited'], [500, 'service_unavailable', 'temporary'], [503, 'storage_unavailable', 'temporary']];
    }

    #[DataProvider('errors')]
    public function test_remote_errors_are_safe_and_never_retried(int $status, string $code, string $reason): void
    {
        $this->fake(['error' => ['code' => $code, 'fields' => [self::SECRET => [self::TOKEN]]], 'errors' => [self::SECRET]], $status, ['Retry-After' => '17']);
        Log::spy();
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $exception = $this->failure($reason, $status);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertSame($code, $exception->remoteCode);
        $this->assertSame(17, $exception->retryAfterSeconds);
        foreach (['error', 'warning', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
        Http::assertSentCount(2);
    }

    public function test_unknown_remote_error_code_is_not_reflected(): void
    {
        $this->fake(['error' => ['code' => self::TOKEN]], 503, ['Retry-After' => self::SECRET]);
        $error = $this->failure('temporary', 503);
        $this->assertNull($error->remoteCode);
        $this->assertNull($error->retryAfterSeconds);
    }

    public static function unexpectedStatuses(): array
    {
        return [[201], [202], [204], [301], [302], [307], [308]];
    }

    #[DataProvider('unexpectedStatuses')]
    public function test_other_success_codes_and_redirects_are_not_confirmations(int $status): void
    {
        $this->fake($this->response($this->roles()), $status, ['Location' => 'https://untrusted.example.test']);
        $this->failure('unexpected_response', $status);
        Http::assertSentCount(2);
    }

    public static function malformedReceipts(): array
    {
        return [['json', '{invalid'], ['json', 'null'], ['json', '[]'], ['status', 'success'], ['status', null],
            ['historical', null], ['historical', 0], ['historical', true], ['current_version', '7'], ['current_version', 6],
            ['current_version', 8], ['current_version', PHP_INT_MAX], ['data.application_code', 'another-app'],
            ['data.operation_id', 'b0000000-0000-4000-8000-000000000002'], ['data.version', 6],
            ['data.roles', []], ['data.roles.0.code', 'renamed'], ['data.roles.0.name', 'Changed'],
            ['data.roles.1.active', true], ['data.roles.0.active', 'true'], ['data.roles.0.description', 'unexpected'],
            ['data.roles.0.access_token', self::TOKEN], ['error', ['code' => 'error_on_success']]];
    }

    #[DataProvider('malformedReceipts')]
    public function test_mismatched_or_malformed_receipt_is_not_confirmation(string $path, mixed $value): void
    {
        $body = $this->response($this->roles());
        if ($path === 'json') {
            $body = $value;
        } else {
            data_set($body, $path, $value);
        }
        $this->fake($body);
        $this->failure('invalid_response', 200);
    }

    public function test_duplicate_requires_current_state_consistent_with_history(): void
    {
        $body = $this->response($this->roles(), 'duplicate', current: 9);
        $body['current_state']['catalog_version'] = 7;
        $this->fake($body);
        $this->failure('invalid_response', 200);
    }

    public function test_connection_does_not_keep_sensitive_transport_exceptions(): void
    {
        $this->fake(Http::failedConnection(self::SECRET));
        $this->failure('connection');
        Http::assertSentCount(2);
    }

    public function test_timeout_does_not_keep_sensitive_transport_exceptions(): void
    {
        $transport = new ConnectException(self::SECRET, new GuzzleRequest('PUT', self::API, ['Authorization' => 'Bearer '.self::TOKEN]), handlerContext: ['errno' => 28]);
        $this->fake(fn () => throw new ConnectionException(self::SECRET, 0, $transport));
        $this->failure('timeout');
    }

    public function test_token_rejection_does_not_send_an_api_request(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);
        try {
            app(AaudAuthClient::class)->syncRoleCatalog(7, self::OPERATION, $this->roles());
            $this->fail('Token rejection must propagate');
        } catch (TechnicalAuthenticationException $exception) {
            $this->assertSame('credentials_rejected', $exception->reason);
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_user_login_client_cannot_replace_the_technical_client(): void
    {
        config(['aaud-auth.client_id' => 'pazysalvo-web']);
        try {
            app(AaudAuthClient::class)->syncRoleCatalog(7, self::OPERATION, $this->roles());
            $this->fail('Only the configured technical client is allowed');
        } catch (TechnicalAuthenticationException $exception) {
            $this->assertSame('configuration', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function test_debugging_client_does_not_expose_cached_credentials(): void
    {
        $this->fake($this->response($this->roles()));
        $client = app(AaudAuthClient::class);
        $client->syncRoleCatalog(7, self::OPERATION, $this->roles());
        ob_start();
        var_dump($client);
        $dump = ob_get_clean();
        foreach ([$dump, print_r($client, true), var_export($client, true), json_encode($client)] as $diagnostic) {
            $this->assertStringNotContainsString(self::TOKEN, $diagnostic);
            $this->assertStringNotContainsString(self::SECRET, $diagnostic);
        }
        $this->expectException(\LogicException::class);
        serialize($client);
    }

    public function test_invalid_operations_never_request_tokens(): void
    {
        $this->failure('invalid_operation', version: 0);
        $this->failure('invalid_operation', version: PHP_INT_MAX);
        $this->failure('invalid_operation', operation: 'not-a-uuid');
        foreach ([[], [['code' => 'x']], [['code' => 'admin', 'name' => 'Admin', 'active' => true, 'permissions' => []]],
            [['code' => 'Invalid', 'name' => 'Admin', 'active' => true]], [['code' => 'admin', 'name' => ' ', 'active' => true]],
            [['code' => 'admin', 'name' => 'Admin', 'active' => 'false']], [...$this->roles(), $this->roles()[0]]] as $roles) {
            $this->failure('invalid_operation', roles: $roles);
        }
        Http::assertNothingSent();
    }
}
