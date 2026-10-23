<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DirectoryClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['aaud-auth' => ['enabled' => true, 'api_url' => 'https://auth.example.test/',
            'issuer' => 'https://keycloak.example.test/realms/aaud',
            'token_url' => 'https://keycloak.example.test/realms/aaud/protocol/openid-connect/token',
            'client_id' => 'pazysalvo-sync', 'client_secret' => 'fixture-secret', 'connect_timeout' => 5,
            'timeout' => 10, 'verify_tls' => true, 'ca_bundle' => null, 'token_expiry_leeway' => 30]]);
    }

    private function body(): array
    {
        return ['data' => [['issuer' => config('aaud-auth.issuer'), 'subject' => 'federated-subject',
            'name' => 'Directory User', 'name_is_fallback' => false, 'username' => 'directory-user', 'email' => null, 'enabled' => true]],
            'page' => 1, 'limit' => 20, 'has_more' => false];
    }

    private function fake(mixed $response, int $status = 200): void
    {
        Http::fake([
            config('aaud-auth.token_url') => Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300]),
            'https://auth.example.test/api/integrations/v1/directory/users*' => is_callable($response) ? $response : Http::response($response, $status),
        ]);
    }

    public function test_directory_transport_preserves_tls_timeouts_fixed_endpoint_and_read_only_method(): void
    {
        $this->fake(function ($request, $options) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-token'));
            $this->assertSame(['q' => 'directory', 'page' => 1, 'limit' => 20], $request->data());

            return Http::response($this->body());
        });
        $this->assertSame($this->body(), app(AaudAuthClient::class)->searchDirectory('directory'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['client_id'] === 'pazysalvo-sync' && $r['grant_type'] === 'client_credentials');
        Http::assertSentCount(2);
    }

    public static function invalidResponses(): array
    {
        return [
            'json' => ['not-json', 200], 'redirect' => [[], 302], 'missing contract' => [[], 200],
            'wrong issuer' => [['data.0.issuer', 'https://evil.example.test'], 200],
            'invalid subject' => [['data.0.subject', 'bad subject'], 200],
            'enabled not bool' => [['data.0.enabled', 1], 200],
            'invalid name' => [['data.0.name', ''], 200],
            'missing provenance' => [['data.0.name_is_fallback', null], 200],
            'wrong page' => [['page', 2], 200],
            'unverified configuration' => [[], 503], 'scope denied' => [[], 403],
            'expired token' => [[], 401], 'rate limit' => [[], 429],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_or_unavailable_directory_never_yields_a_verified_identity(mixed $body, int $status): void
    {
        if (is_array($body) && array_is_list($body) && count($body) === 2 && is_string($body[0])) {
            $fixture = $this->body();
            data_set($fixture, $body[0], $body[1]);
            $body = $fixture;
        }
        $this->fake($body, $status);
        try {
            app(AaudAuthClient::class)->searchDirectory('directory');
            $this->fail('Invalid directory must be rejected');
        } catch (AaudAuthRequestException $e) {
            $this->assertStringNotContainsString('fixture-token', (string) $e);
            $this->assertStringNotContainsString('fixture-secret', (string) $e);
            $this->assertNull($e->getPrevious());
        }
    }

    public static function invalidConfiguration(): array
    {
        return [['aaud-auth.enabled', false], ['aaud-auth.issuer', null], ['aaud-auth.issuer', 'https://evil.test/realms/aaud/'],
            ['aaud-auth.issuer', 'http://example.test/realms/aaud'], ['aaud-auth.issuer', 'https://user:secret@example.test/realms/aaud'],
            ['aaud-auth.verify_tls', false]];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_insecure_configuration_fails_before_network(string $key, mixed $value): void
    {
        config([$key => $value]);
        try {
            app(AaudAuthClient::class)->searchDirectory('directory');
            $this->fail('Configuration must be rejected');
        } catch (AaudAuthRequestException) {
            Http::assertNothingSent();
        }
    }

    public function test_timeout_is_sanitized_and_not_retried(): void
    {
        $this->fake(function () {
            throw new ConnectionException('timeout fixture-token fixture-secret');
        });
        try {
            app(AaudAuthClient::class)->searchDirectory('directory');
            $this->fail('Timeout required');
        } catch (AaudAuthRequestException $e) {
            $this->assertSame('timeout', $e->reason);
            $this->assertStringNotContainsString('fixture-token', (string) $e);
            $this->assertStringNotContainsString('fixture-secret', (string) $e);
        }
    }
}
