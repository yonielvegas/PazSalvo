<?php

namespace Tests\Feature\Oidc;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\UserSsoIdentity;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OidcFixture as Idp;
use Tests\TestCase;

class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $claims = [];

    private array $metadata = [];

    private ?array $tokenResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-oidc.enabled' => true, 'aaud-oidc.issuer' => Idp::ISSUER, 'aaud-oidc.client_id' => 'pazysalvo-web',
            'aaud-oidc.client_secret' => 'private-web-secret', 'app.url' => 'http://localhost:8000',
            'aaud-oidc.redirect_uri' => 'http://localhost:8000/auth/aaud/callback', 'aaud-oauth.automatic' => false,
            'security.internal_network.enabled' => false, 'aaud-oauth.portal_url' => 'https://auth.example.test/portal']);
        Http::preventStrayRequests();
        $this->seed(MasterDataSeeder::class);
        $this->user = User::factory()->create(['password' => Hash::make('Local.Password123!'), 'must_change_password' => false]);
        $this->user->assignRole('operador');
        UserSsoIdentity::create(['user_id' => $this->user->id, 'issuer' => Idp::ISSUER, 'subject' => 'institutional-subject']);
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            if ($request->url() === Idp::ISSUER.'/.well-known/openid-configuration') {
                return Http::response(array_replace(Idp::metadata(), $this->metadata));
            }
            if ($request->url() === Idp::ISSUER.'/protocol/openid-connect/certs') {
                return Http::response(Idp::jwks());
            }
            if ($request->url() === Idp::ISSUER.'/protocol/openid-connect/token') {
                $this->assertSame('POST', $request->method());
                $this->assertSame('authorization_code', $request['grant_type']);
                $this->assertSame('pazysalvo-web', $request['client_id']);
                $this->assertSame('http://localhost:8000/auth/aaud/callback', $request['redirect_uri']);
                $this->assertSame($this->flow['verifier'], $request['code_verifier']);
                $this->assertTrue($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
                $this->assertTrue($request->hasHeader('Authorization', 'Basic '.base64_encode('pazysalvo-web:private-web-secret')));

                return Http::response($this->tokenResponse ?? ['id_token' => Idp::token($this->flow, $this->claims),
                    'access_token' => 'private-access-token', 'token_type' => 'Bearer', 'expires_in' => 300]);
            }
            throw new \LogicException('Unexpected endpoint; no calls to AAUD OAUTH, AD or synchronization are allowed');
        });
    }

    private array $flow = [];

    private function start(): void
    {
        $response = $this->get('/auth/aaud/redirect?returnTo=https://evil.example');
        $response->assertRedirect()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->flow = session('oidc_flow') ?? [];
        $this->assertNotEmpty($this->flow);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $this->flow['verifier'], true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertArrayNotHasKey('prompt', $query); // Keycloak may reuse its existing SSO session.
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    private function completeLogin(array $changes = []): TestResponse
    {
        return $this->get('/auth/aaud/callback?'.http_build_query(array_replace(['state' => $this->flow['state'], 'code' => 'private-code', 'iss' => Idp::ISSUER], $changes)));
    }

    public function test_linked_active_user_gets_local_session_and_only_local_roles(): void
    {
        $this->user->update(['is_login_blocked' => true, 'login_attempts' => 3, 'must_change_password' => true]);
        $before = $this->user->fresh();
        $this->start();
        $oldSession = session()->getId();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->assertAuthenticatedAs($this->user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame('oidc', session('auth_method'));
        $this->assertNull(session('oidc_flow'));
        $this->assertSame($before->session_version + 1, session('auth_session_version'));
        $this->assertTrue($this->user->fresh()->is_login_blocked);
        $this->assertSame(3, $this->user->fresh()->login_attempts);
        $this->assertTrue($this->user->fresh()->must_change_password);
        $this->assertSame($before->password, $this->user->fresh()->password);
        $this->assertSame(['operador'], $this->user->fresh()->getRoleNames()->all());
        $this->assertFalse($this->user->fresh()->hasRole('admin'));
        $this->assertStringNotContainsString('private-access-token', json_encode(session()->all()));
        $this->get(route('paz-salvo.index'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'login.sso.succeeded', 'subject_id' => $this->user->id]);
        $this->assertDatabaseCount('aaud_auth_access_operations', 0);
    }

    public static function ssoEntrypoints(): array
    {
        return [['portal'], ['login']];
    }

    #[DataProvider('ssoEntrypoints')]
    public function test_each_entrypoint_completes_callback_authorizes_landing_and_logs_out_without_central_logout(string $entry): void
    {
        if ($entry === 'login') {
            $this->get('/login')->assertInertia(fn ($page) => $page->component('auth/login')->where('oidc_login_enabled', true));
        }
        // Portal launch resolves to this exact existing endpoint; the portal suite verifies that leg.
        $this->withHeader('Referer', $entry === 'portal' ? 'http://localhost:18000/portal' : 'http://localhost:8000/login');
        $this->start();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame('oidc', session('auth_method'));
        $this->assertSame('institutional-subject', session('auth_sso_subject'));
        $this->assertSame(Idp::ISSUER, session('auth_sso_issuer'));
        $this->assertSame(['operador'], $this->user->fresh()->getRoleNames()->all());
        $this->assertTrue($this->user->fresh()->can('consultar paz y salvo'));
        $this->get(route('paz-salvo.index'))->assertOk();
        $this->post('/logout')->assertRedirect('https://auth.example.test/portal');
        $this->assertGuest();
        Http::assertSentCount(4); // Discovery, token and JWKS only; no central logout.
        $this->assertDatabaseCount('aaud_auth_access_operations', 0);
    }

    public function test_local_entrypoint_reaches_authorized_landing_and_returns_to_local_login(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Local.Password123!'])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame('local', session('auth_method'));
        $this->get(route('paz-salvo.index'))->assertOk();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_login_keeps_sso_choice_on_partial_reload_and_respects_explicit_disable(): void
    {
        $version = app(HandleInertiaRequests::class)->version(Request::create('/login'));
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'auth/login', 'X-Inertia-Partial-Data' => 'flash'];
        $this->get('/login', $headers)->assertOk()->assertJsonPath('props.oidc_login_enabled', true);
        config(['aaud-oidc.enabled' => false]);
        $this->get('/login', $headers)->assertOk()->assertJsonPath('props.oidc_login_enabled', false);
    }

    public static function deniedUsers(): array
    {
        return [['unlinked'], ['inactive'], ['no_role'], ['inactive_role'], ['other_issuer'], ['other_subject']];
    }

    #[DataProvider('deniedUsers')]
    public function test_only_explicit_valid_links_and_local_operational_accounts_can_enter(string $case): void
    {
        match ($case) {
            'unlinked' => $this->user->ssoIdentity()->delete(),
            'inactive' => $this->user->update(['is_active' => false]),
            'no_role' => $this->user->syncRoles([]),
            'inactive_role' => DB::table('roles')->where('name', 'operador')->update(['is_active' => false]),
            'other_issuer' => $this->user->ssoIdentity()->update(['issuer' => 'https://other.example/realms/aaud']),
            'other_subject' => $this->user->ssoIdentity()->update(['subject' => 'different-subject']),
        };
        $count = User::count();
        $this->start();
        $this->completeLogin()->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
        $this->assertSame($count, User::count());
        $this->assertDatabaseCount('aaud_auth_access_operations', 0);
    }

    public static function badCallbacks(): array
    {
        return [[['state' => 'wrong']], [['state' => ['invalid']]], [['code' => '']], [['code' => ['invalid']]],
            [['error' => 'access_denied', 'error_description' => 'private-provider-message']], [['iss' => 'https://evil.example']]];
    }

    #[DataProvider('badCallbacks')]
    public function test_bad_callbacks_fail_before_token_exchange_and_consume_the_flow(array $changes): void
    {
        $this->start();
        $this->completeLogin($changes)->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
        $this->assertNull(session('oidc_flow'));
        Http::assertSentCount(1);
    }

    public function test_expired_transaction_and_replayed_consumed_callback_never_exchange_a_code(): void
    {
        $this->start();
        session()->put('oidc_flow', [...$this->flow, 'issued_at' => time() - 301]);
        $this->completeLogin()->assertRedirect(route('login'));
        $this->completeLogin()->assertRedirect(route('login'));
        Http::assertSentCount(1);
        $this->assertGuest();
    }

    public function test_concurrent_replay_guard_rejects_a_previously_consumed_state(): void
    {
        $this->start();
        Cache::add('oidc:consumed:'.hash('sha256', $this->flow['state']), true, 600);
        $this->completeLogin()->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public static function badTokens(): array
    {
        return [[['nonce' => 'wrong']], [['iss' => 'https://evil.example']], [['aud' => 'pazysalvo-sync']], [['exp' => 1]]];
    }

    #[DataProvider('badTokens')]
    public function test_invalid_id_tokens_never_create_a_local_session(array $claims): void
    {
        $this->claims = $claims;
        $this->start();
        $this->completeLogin()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('oidc_flow'));
        $this->assertDatabaseMissing('audit_logs', ['event' => 'login.sso.succeeded']);
    }

    public function test_logout_returns_to_portal_without_central_logout(): void
    {
        $this->start();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->post('/logout')->assertRedirect('https://auth.example.test/portal');
        $this->assertGuest();
        $this->assertNull(session('auth_method'));
        Http::assertSentCount(4);
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Local.Password123!'])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_oidc_logout_supports_inertia_and_normal_sso_reentry(): void
    {
        $this->start();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->withHeader('X-Inertia', 'true')->post('/logout')
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://auth.example.test/portal');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertNull(session('auth_method'));
        $this->assertNull(session('auth_sso_subject'));
        Http::assertSentCount(4); // Logout made no provider request.
        $this->flushHeaders();
        $this->start(); // Existing OIDC flow without prompt=login or central logout.
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame('oidc', session('auth_method'));
    }

    public function test_linked_account_alternates_between_local_and_oidc_sessions(): void
    {
        $this->withSession(['auth_method' => 'oidc', 'auth_sso_identity_id' => 999,
            'auth_sso_issuer' => Idp::ISSUER, 'auth_sso_subject' => 'stale', 'oidc_flow' => ['stale']])
            ->post('/login', ['email' => $this->user->email, 'password' => 'Local.Password123!'])
            ->assertRedirect();
        $this->assertSame('local', session('auth_method'));
        $this->assertNull(session('auth_sso_identity_id'));
        $this->assertNull(session('auth_sso_issuer'));
        $this->assertNull(session('auth_sso_subject'));
        $this->assertNull(session('oidc_flow'));
        $this->post('/logout')->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->withSession(['auth_method' => 'local']);
        $this->start();
        $this->completeLogin();
        $this->assertSame('oidc', session('auth_method'));
        $this->post('/logout')->assertRedirect('https://auth.example.test/portal');
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Local.Password123!']);
        $this->assertSame('local', session('auth_method'));
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_unlink_invalidates_only_the_local_sso_session_on_the_next_request(): void
    {
        $this->start();
        $this->completeLogin();
        $this->user->ssoIdentity()->delete();
        $this->get(route('paz-salvo.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertSentCount(4);
    }

    public function test_disabled_sso_does_not_make_http_calls_or_disable_local_login(): void
    {
        config(['aaud-oidc.enabled' => false]);
        $this->get('/auth/aaud/redirect')->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Local.Password123!'])->assertRedirect();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_external_discovery_endpoints_are_rejected_before_sending_any_credentials(): void
    {
        $this->metadata = ['token_endpoint' => 'https://evil.example/token'];
        $this->get('/auth/aaud/redirect')->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertSentCount(1);
    }

    public function test_malformed_token_response_is_sanitized_and_flow_cannot_be_retried(): void
    {
        $this->tokenResponse = ['error' => 'invalid_client', 'error_description' => 'private-web-secret'];
        $this->start();
        $this->completeLogin()->assertRedirect(route('login'));
        $this->completeLogin()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertStringNotContainsString('private-web-secret', json_encode(DB::table('audit_logs')->get()->all()));
        Http::assertSentCount(3);
    }

    public static function unsafeConfiguration(): array
    {
        return [['aaud-oidc.client_id', 'pazysalvo-sync'], ['aaud-oidc.redirect_uri', 'https://evil.example/callback'],
            ['aaud-oidc.issuer', 'http://external.example/realms/aaud'], ['aaud-oauth.verify_tls', false],
            ['session.driver', 'cookie']];
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_unsafe_configuration_never_starts_oauth(string $field, mixed $value): void
    {
        config([$field => $value]);
        $this->get('/auth/aaud/redirect')->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_provider_timeout_consumes_flow_and_never_logs_provider_details(): void
    {
        $this->start();
        Http::fake(fn () => throw new ConnectionException('private-web-secret private-code private-access-token'));
        $this->completeLogin()->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('oidc_flow'));
        $metadata = json_encode(DB::table('audit_logs')->get()->all());
        $this->assertStringNotContainsString('private-web-secret', $metadata);
        $this->assertStringNotContainsString('private-code', $metadata);
    }

    public function test_sso_never_unlocks_local_password_attempts_even_after_logout(): void
    {
        $this->user->update(['is_login_blocked' => true, 'login_attempts' => 3]);
        $this->start();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->post('/logout')->assertRedirect('https://auth.example.test/portal');
        $this->post('/login', ['email' => $this->user->email, 'password' => 'Local.Password123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue($this->user->fresh()->is_login_blocked);
    }

    public function test_sso_only_passwordless_account_logs_in_and_admin_reset_preserves_both_methods(): void
    {
        $this->user->update(['password' => null, 'local_login_enabled' => false, 'must_change_password' => false]);
        $this->start();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->assertAuthenticatedAs($this->user);
        $this->get(route('paz-salvo.index'))->assertOk();
        $this->assertNull($this->user->fresh()->password);
        $this->assertFalse($this->user->fresh()->local_login_enabled);
        $this->post('/logout');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->patch('/admin/users/'.$this->user->id.'/reset-password')->assertSessionHasNoErrors();
        Auth::logout();
        $this->post('/login', ['email' => $this->user->email, 'password' => config('security.temporary_user_password')])->assertRedirect(route('password.force-change'));
        $this->post('/logout');
        $this->start();
        $this->completeLogin()->assertRedirect(route('paz-salvo.index'));
        $this->assertAuthenticatedAs($this->user);
        $this->assertTrue($this->user->fresh()->local_login_enabled);
    }
}
