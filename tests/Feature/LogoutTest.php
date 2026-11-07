<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSsoIdentity;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-oauth.automatic' => false, 'aaud-oauth.portal_url' => 'https://auth.example.test/portal',
            'security.internal_network.enabled' => false]);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Logout must not contact another service'));
        $this->seed(MasterDataSeeder::class);
        $this->user = User::factory()->create(['password' => 'Synthetic.Password123!', 'must_change_password' => false]);
        $this->user->assignRole('operador');
    }

    public function test_local_login_logout_returns_to_local_login_even_for_a_linked_account(): void
    {
        UserSsoIdentity::create(['user_id' => $this->user->id, 'issuer' => 'https://id.example.test/realms/aaud', 'subject' => 'synthetic-user']);
        $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'Synthetic.Password123!'])
            ->assertRedirect()->assertSessionHas('auth_method', 'local');
        $this->post(route('logout'), ['auth_method' => 'oidc', 'returnTo' => 'https://evil.example'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('auth_method'));
        Http::assertNothingSent();
    }

    public function test_database_session_is_destroyed_and_csrf_rotated(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'Synthetic.Password123!']);
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->assertDatabaseHas('sessions', ['id' => $oldId]);
        $this->withCookie(config('session.cookie'), $oldId)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertNull(session('auth_method'));
        $this->get(route('paz-salvo.index'))->assertRedirect(route('login'));
    }

    public function test_oidc_destination_ignores_browser_parameters_and_clears_only_this_session(): void
    {
        $before = $this->user->fresh()->getAttributes();
        $this->actingAs($this->user)->withSession(['auth_method' => 'oidc', 'auth_sso_identity_id' => 1,
            'auth_sso_issuer' => 'https://id.example.test/realms/aaud', 'auth_sso_subject' => 'synthetic-user',
            'oidc_flow' => ['state' => 'temporary'], 'unrelated_session_data' => 'value'])
            ->post(route('logout').'?returnTo=https://evil.example', ['portal_url' => 'https://evil.example', 'auth_method' => 'local'])
            ->assertRedirect('https://auth.example.test/portal')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        foreach (['auth_method', 'auth_sso_identity_id', 'auth_sso_issuer', 'auth_sso_subject', 'oidc_flow', 'unrelated_session_data'] as $key) {
            $this->assertNull(session($key));
        }
        // Keep Fortify's normal remember-token rotation; roles and session policy stay intact.
        $this->assertSame(array_diff_key($before, array_flip(['remember_token', 'updated_at'])),
            array_diff_key($this->user->fresh()->getAttributes(), array_flip(['remember_token', 'updated_at'])));
        Http::assertNothingSent();
    }

    public function test_legacy_or_unknown_method_returns_to_local_login(): void
    {
        foreach ([null, 'local', 'unknown', ['oidc']] as $method) {
            $this->actingAs($this->user)->withSession(['auth_method' => $method])
                ->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
        Http::assertNothingSent();
    }

    public function test_session_regeneration_keeps_method_until_logout(): void
    {
        $this->actingAs($this->user)->withSession(['auth_method' => 'oidc']);
        session()->regenerate();
        $this->assertSame('oidc', session('auth_method'));
        $this->post(route('logout'))->assertRedirect('https://auth.example.test/portal');
    }

    public static function unsafePortals(): array
    {
        return [[null], [''], ['//evil.example/portal'], ['javascript:alert(1)'],
            ['http://external.example/portal'], ['https://name:password@auth.example.test/portal'],
            ['https://auth.example.test/portal?returnTo=https://evil.example'], ['https://auth.example.test/portal#fragment'],
            ['https://auth.example.test/logout'], ["https://auth.example.test/portal\r\nInjected: value"],
            ['https://auth.example.test/\\portal']];
    }

    #[DataProvider('unsafePortals')]
    public function test_bad_portal_configuration_still_logs_out_safely(mixed $url): void
    {
        config(['aaud-oauth.portal_url' => $url]);
        $this->actingAs($this->user)->withSession(['auth_method' => 'oidc'])
            ->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_local_development_portal_uses_existing_applications_route(): void
    {
        config(['aaud-oauth.portal_url' => 'http://localhost:18000/portal']);
        $this->actingAs($this->user)->withSession(['auth_method' => 'oidc'])
            ->post(route('logout'))->assertRedirect('http://localhost:18000/portal');
    }

    public function test_expired_and_repeated_logout_do_not_restore_authentication(): void
    {
        $this->withSession(['auth_method' => 'oidc'])->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($this->user)->withSession(['auth_method' => 'local'])->post(route('logout'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_get_logout_is_not_allowed_and_json_logout_retains_fortify_contract(): void
    {
        $this->actingAs($this->user)->withSession(['auth_method' => 'oidc']);
        $this->get(route('logout'))->assertStatus(405);
        $this->assertAuthenticated();
        $this->postJson(route('logout'))->assertNoContent();
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_logout_post_rejects_missing_csrf_and_accepts_a_valid_token(): void
    {
        // Laravel disables CSRF in tests by default; enable the actual middleware check here.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $token = str_repeat('a', 40);
        $this->actingAs($this->user)->withSession(['auth_method' => 'local', '_token' => $token])
            ->post(route('logout'))->assertStatus(419);
        $this->assertAuthenticated();
        $this->post(route('logout'), ['_token' => $token])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($token, session()->token());
    }
}
