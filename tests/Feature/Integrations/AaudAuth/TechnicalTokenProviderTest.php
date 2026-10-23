<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException as AuthenticationFailure;
use App\Integrations\AaudAuth\Keycloak\TechnicalTokenProvider;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TechnicalTokenProviderTest extends TestCase
{
    private const URL = 'https://keycloak.example.test/realms/aaud/protocol/openid-connect/token';

    private const SECRET = 'fixture-secret-not-real';

    private const TOKEN = 'fixture.opaque.access-token';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['aaud-auth' => [
            'enabled' => true, 'token_url' => self::URL, 'client_id' => 'pazysalvo-sync',
            'client_secret' => self::SECRET, 'connect_timeout' => 5, 'timeout' => 10,
            'verify_tls' => true, 'ca_bundle' => null, 'token_expiry_leeway' => 30,
        ]]);
    }

    private function payload(): array
    {
        return ['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 60];
    }

    private function assertFailure(string $reason, ?int $status = null, ?TechnicalTokenProvider $provider = null): AuthenticationFailure
    {
        try {
            ($provider ?? new TechnicalTokenProvider)->getToken();
            $this->fail('Expected a safe technical authentication error');
        } catch (AuthenticationFailure $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($status, $exception->httpStatus);
            $this->assertNull($exception->getPrevious());
            $diagnostic = (string) $exception;
            $this->assertStringNotContainsString(self::SECRET, $diagnostic);
            $this->assertStringNotContainsString(self::TOKEN, $diagnostic);
            // Inspect module arguments only: a PHPUnit caller holds its complete fake registry.
            foreach ($exception->getTrace() as $frame) {
                if (str_starts_with($frame['class'] ?? '', 'App\\Integrations\\AaudAuth\\')) {
                    $arguments = print_r($frame['args'] ?? [], true);
                    $this->assertStringNotContainsString(self::SECRET, $arguments);
                    $this->assertStringNotContainsString(self::TOKEN, $arguments);
                }
            }

            return $exception;
        }
    }

    public function test_disabled_and_construction_never_send_requests(): void
    {
        Http::fake();
        config(['aaud-auth.enabled' => false]);
        $provider = app(TechnicalTokenProvider::class);
        Http::assertNothingSent();
        $this->assertFailure(AuthenticationFailure::DISABLED, provider: $provider);
        Http::assertNothingSent();
    }

    public static function invalidConfigurations(): array
    {
        return [
            ['client_id', null], ['client_id', ''], ['client_id', 'pazysalvo-web'],
            ['client_secret', null], ['client_secret', ''], ['client_secret', '   '],
            ['token_url', null], ['token_url', 'not-a-url'], ['token_url', 'ftp://localhost/token'],
            ['token_url', 'http://external.example.test/token'],
            ['token_url', 'https://user:pass@example.test/token'],
            ['token_url', 'https://example.test/token?secret=unsafe'], ['token_url', 'https://example.test/token#fragment'],
            ['connect_timeout', 0], ['connect_timeout', true], ['timeout', -1], ['timeout', 4], ['timeout', 'invalid'],
            ['token_expiry_leeway', -1], ['verify_tls', false], ['ca_bundle', '/nonexistent/ca.pem'],
            ['ca_bundle', 'https://example.test/ca.pem'],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_never_sends_a_request(string $key, mixed $value): void
    {
        Http::fake();
        config(['aaud-auth.'.$key => $value]);
        $error = $this->assertFailure(AuthenticationFailure::CONFIGURATION);
        $this->assertNotEmpty($error->configurationFields);
        $this->assertStringNotContainsString(self::SECRET, json_encode($error));
        Http::assertNothingSent();
    }

    public function test_correct_form_timeouts_tls_and_no_redirects(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'pazysalvo-sync', 'client_secret' => self::SECRET], $request->data());
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response($this->payload());
        });
        $token = (new TechnicalTokenProvider)->getToken();
        $this->assertSame(self::TOKEN, $token->accessToken());
        $this->assertSame('Bearer', $token->tokenType);
        Http::assertSentCount(1);
    }

    public function test_reuses_until_leeway_then_refreshes_and_does_not_reuse_across_instances(): void
    {
        $this->freezeTime();
        Http::fake([self::URL => Http::sequence()->push($this->payload())->push($this->payload())->push($this->payload())]);
        $provider = new TechnicalTokenProvider;
        $first = $provider->getToken();
        $this->travel(29)->seconds();
        $this->assertSame($first, $provider->getToken());
        $this->travel(1)->seconds();
        $this->assertNotSame($first, $provider->getToken());
        $this->assertNotSame($first, (new TechnicalTokenProvider)->getToken());
        Http::assertSentCount(3);
    }

    public function test_refreshes_after_expiry(): void
    {
        $this->freezeTime();
        Http::fake([self::URL => Http::sequence()->push($this->payload())->push($this->payload())]);
        $provider = new TechnicalTokenProvider;
        $first = $provider->getToken();
        $this->travel(61)->seconds();
        $this->assertNotSame($first, $provider->getToken());
        Http::assertSentCount(2);
    }

    public function test_disabling_or_changing_credentials_invalidates_memory_cache(): void
    {
        Http::fake([self::URL => Http::sequence()->push($this->payload())->push($this->payload())]);
        $provider = new TechnicalTokenProvider;
        $first = $provider->getToken();
        config(['aaud-auth.enabled' => false]);
        $this->assertFailure(AuthenticationFailure::DISABLED, provider: $provider);
        Http::assertSentCount(1);
        config(['aaud-auth.enabled' => true, 'aaud-auth.client_secret' => 'changed-fixture-secret']);
        $this->assertNotSame($first, $provider->getToken());
        Http::assertSentCount(2);
    }

    public static function failedResponses(): array
    {
        return [
            [400, ['error' => 'invalid_client'], AuthenticationFailure::REJECTED],
            [400, ['error' => 'unauthorized_client'], AuthenticationFailure::REJECTED],
            [401, [], AuthenticationFailure::REJECTED], [403, [], AuthenticationFailure::REJECTED],
            [429, [], AuthenticationFailure::TEMPORARY], [500, [], AuthenticationFailure::TEMPORARY], [503, [], AuthenticationFailure::TEMPORARY],
            [302, [], AuthenticationFailure::INVALID_RESPONSE], [400, ['error' => 'invalid_request'], AuthenticationFailure::INVALID_RESPONSE],
        ];
    }

    #[DataProvider('failedResponses')]
    public function test_http_and_oauth_failures_are_sanitized_without_retry(int $status, array $body, string $reason): void
    {
        $body['error_description'] = self::SECRET.' '.self::TOKEN;
        Http::fake([self::URL => Http::response($body, $status, ['Location' => 'https://untrusted.example.test'])]);
        Log::spy();
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            $this->assertFailure($reason, $status);
        } finally {
            ini_set('zend.exception_ignore_args', $before);
        }
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public static function invalidPayloads(): array
    {
        $valid = ['access_token' => self::TOKEN, 'token_type' => 'Bearer', 'expires_in' => 60];

        return [
            ['{broken'], ['null'], ['[]'], ['"string"'], [[]],
            [['token_type' => 'Bearer', 'expires_in' => 60]],
            [[...$valid, 'access_token' => '']], [[...$valid, 'access_token' => 12]], [[...$valid, 'access_token' => "bad\r\nvalue"]],
            [[...$valid, 'token_type' => 'Basic']], [[...$valid, 'token_type' => null]],
            [[...$valid, 'expires_in' => null]], [[...$valid, 'expires_in' => 0]], [[...$valid, 'expires_in' => -1]],
            [[...$valid, 'expires_in' => 30]], [[...$valid, 'expires_in' => '60']], [[...$valid, 'expires_in' => true]],
            [[...$valid, 'expires_in' => 60.5]], [[...$valid, 'expires_in' => PHP_INT_MAX]],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_oauth_payload_is_rejected(mixed $body): void
    {
        Http::fake([self::URL => Http::response($body)]);
        $this->assertFailure(AuthenticationFailure::INVALID_RESPONSE, 200);
        Http::assertSentCount(1);
    }

    public function test_timeout_is_distinct_and_transport_request_is_not_chained(): void
    {
        $transport = new ConnectException(self::SECRET, new GuzzleRequest('POST', self::URL, [], self::SECRET), handlerContext: ['errno' => 28]);
        Http::fake(fn () => throw new ConnectionException(self::SECRET, 0, $transport));
        $this->assertFailure(AuthenticationFailure::TIMEOUT);
    }

    public function test_connection_error_is_distinct(): void
    {
        Http::fake([self::URL => Http::failedConnection(self::SECRET)]);
        $this->assertFailure(AuthenticationFailure::CONNECTION);
    }

    public function test_http_is_only_allowed_in_local_testing_loopback(): void
    {
        $url = 'http://localhost:8080/realms/aaud/protocol/openid-connect/token';
        config(['aaud-auth.token_url' => $url]);
        Http::fake([$url => Http::response($this->payload())]);
        $this->assertSame(self::TOKEN, (new TechnicalTokenProvider)->getToken()->accessToken());
        config(['app.env' => 'production']);
        $this->assertFailure(AuthenticationFailure::CONFIGURATION);
        Http::assertSentCount(1);
    }

    public function test_configured_ca_is_used_and_provider_debug_does_not_expose_credentials(): void
    {
        config(['aaud-auth.ca_bundle' => __FILE__]);
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(__FILE__, $options['verify']);

            return Http::response($this->payload());
        });
        $provider = new TechnicalTokenProvider;
        $provider->getToken();
        foreach ([print_r($provider, true), var_export($provider, true), json_encode($provider)] as $debug) {
            $this->assertStringNotContainsString(self::SECRET, $debug);
            $this->assertStringNotContainsString(self::TOKEN, $debug);
        }
        $this->expectException(\LogicException::class);
        serialize($provider);
    }

    public function test_network_duration_is_subtracted_from_validity(): void
    {
        $this->freezeTime();
        Http::fake(function () {
            $this->travel(31)->seconds();

            return Http::response($this->payload());
        });
        $this->assertFailure(AuthenticationFailure::INVALID_RESPONSE, 200);
    }
}
