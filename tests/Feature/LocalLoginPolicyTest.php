<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Services\AuditLogger;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalLoginPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $target;

    private const ISSUER = 'https://id.example.test/realms/aaud';

    protected function setUp(): void
    {
        parent::setUp();
        config(['aaud-oauth.automatic' => false, 'security.internal_network.enabled' => false]);
        Http::preventStrayRequests();
        $this->seed(MasterDataSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->target = User::factory()->create(['password' => Hash::make('Local.Password123!'), 'must_change_password' => false]);
        $this->target->assignRole('operador');
        $this->actingAs($this->admin);
    }

    private function ssoOnly(bool $historicalHash = false): User
    {
        $this->target->update(['local_login_enabled' => false, 'password' => $historicalHash ? Hash::make('Local.Password123!') : null, 'must_change_password' => false]);

        return $this->target->fresh();
    }

    private function directory(): void
    {
        config(['aaud-oauth.enabled' => true, 'aaud-oauth.issuer' => self::ISSUER,
            'aaud-oauth.api_url' => 'https://auth.example.test', 'aaud-oauth.token_url' => 'https://id.example.test/token',
            'aaud-oauth.client_id' => 'pazysalvo-sync', 'aaud-oauth.client_secret' => 'fixture-only']);
        Http::fake(['https://id.example.test/token' => Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'directory.read']),
            'https://auth.example.test/api/integrations/v1/directory/users*' => Http::response(['data' => [['issuer' => self::ISSUER, 'subject' => 'institutional-subject',
                'name' => 'Institutional Test', 'username' => 'institutional.test', 'name_is_fallback' => false, 'email' => 'institutional@example.test', 'enabled' => true]],
                'page' => 1, 'limit' => 20, 'has_more' => false])]);
    }

    private function creation(array $changes = []): array
    {
        return array_replace(['name' => 'Local Test', 'email' => 'new.local@example.test', 'agency_id' => $this->admin->agency_id, 'role' => 'operador', 'is_active' => true], $changes);
    }

    public function test_local_creation_keeps_a_working_password_and_ignores_browser_policy_overrides(): void
    {
        $this->post('/admin/users', $this->creation(['local_login_enabled' => false]))->assertSessionHasNoErrors();
        $user = User::where('email', 'new.local@example.test')->sole();
        $this->assertTrue($user->local_login_enabled);
        $this->assertTrue(Hash::check(config('security.temporary_user_password'), $user->password));
        Auth::logout();
        $this->post('/login', ['email' => $user->email, 'password' => config('security.temporary_user_password')])->assertRedirect(route('password.force-change'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();
    }

    public function test_new_linked_account_is_passwordless_without_requiring_a_configured_temporary_password(): void
    {
        $this->directory();
        config(['security.temporary_user_password' => '']);
        $this->post('/admin/users', $this->creation(['local_login_enabled' => true, 'sso_identity' => ['issuer' => self::ISSUER,
            'subject' => 'institutional-subject', 'query' => 'institutional', 'page' => 1, 'confirmed' => true]]))->assertRedirect()->assertSessionHasNoErrors();
        $user = User::where('email', 'institutional@example.test')->sole();
        $this->assertFalse($user->local_login_enabled);
        $this->assertNull($user->password);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->password_reset_at);
        $this->assertNull($user->password_reset_by);
        $this->assertSame('institutional-subject', $user->ssoIdentity->subject);
        $this->assertSame(['operador'], $user->getRoleNames()->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.sso_only.created', 'subject_id' => $user->id]);
    }

    public static function hashes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('hashes')]
    public function test_sso_only_rejects_password_login_without_mutating_password_attempt_counters(bool $historicalHash): void
    {
        $target = $this->ssoOnly($historicalHash);
        $target->update(['login_attempts' => 2, 'is_login_blocked' => true]);
        Auth::logout();
        $this->post('/login', ['email' => $target->email, 'password' => 'Local.Password123!'])
            ->assertSessionHasErrors(['email' => 'Correo o contraseña incorrectos.']);
        $this->assertGuest();
        $this->assertSame(2, $target->fresh()->login_attempts);
        $this->assertTrue($target->fresh()->is_login_blocked);
        $this->assertFalse($target->fresh()->local_login_enabled);
    }

    public function test_guard_authentication_and_remember_cookie_cannot_bypass_the_policy(): void
    {
        $target = $this->ssoOnly(true);
        $target->update(['remember_token' => 'historical-cookie']);
        Auth::logout();
        $this->assertFalse(Auth::guard('web')->attempt(['email' => $target->email, 'password' => 'Local.Password123!']));
        $this->assertNull(Auth::guard('web')->getProvider()->retrieveByToken($target->id, 'historical-cookie'));
        $this->assertNotNull(Auth::guard('web')->getProvider()->retrieveById($target->id)); // Session identity lookup remains usable by OIDC.
    }

    public function test_fortify_authenticate_callback_cannot_bypass_the_policy(): void
    {
        $target = $this->ssoOnly(true);
        try {
            (Fortify::$authenticateUsingCallback)(Request::create('/login', 'POST', ['email' => $target->email, 'password' => 'Local.Password123!']));
            $this->fail('Fallback authentication must reject SSO-only');
        } catch (ValidationException $failure) {
            $this->assertSame(['Correo o contraseña incorrectos.'], $failure->errors()['email']);
        }
        $this->assertSame(0, $target->fresh()->login_attempts);
    }

    public static function passwordActions(): array
    {
        return [[ResetUserPassword::class], [UpdateUserPassword::class]];
    }

    #[DataProvider('passwordActions')]
    public function test_non_administrative_password_actions_cannot_enable_or_change_sso_only(string $action): void
    {
        $target = $this->ssoOnly(true);
        $before = $target->password;
        try {
            $instance = app($action);
            $method = $action === ResetUserPassword::class ? 'reset' : 'update';
            $instance->$method($target, ['current_password' => 'Local.Password123!', 'password' => 'New.Password123!', 'password_confirmation' => 'New.Password123!']);
            $this->fail('Password management must reject SSO-only');
        } catch (ValidationException) {
            $this->assertSame($before, $target->fresh()->password);
            $this->assertFalse($target->fresh()->local_login_enabled);
            $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_change_rejected', 'subject_id' => $target->id]);
        }
    }

    public function test_forced_change_endpoints_never_render_or_set_a_password_for_sso_only(): void
    {
        $target = $this->ssoOnly();
        $this->actingAs($target)->get('/cambiar-contrasena-obligatoria')->assertRedirect()->assertSessionHasErrors('password');
        $this->put('/cambiar-contrasena-obligatoria', ['password' => 'New.Password123!', 'password_confirmation' => 'New.Password123!'])->assertSessionHasErrors('password');
        $this->assertNull($target->fresh()->password);
        $this->assertFalse($target->fresh()->local_login_enabled);
    }

    public function test_admin_reset_atomically_enables_local_login_and_retains_the_identity(): void
    {
        $target = $this->ssoOnly();
        UserSsoIdentity::create(['user_id' => $target->id, 'issuer' => self::ISSUER, 'subject' => 'institutional-subject']);
        $this->patch('/admin/users/'.$target->id.'/reset-password')->assertSessionHasNoErrors();
        $target->refresh();
        $this->assertTrue($target->local_login_enabled);
        $this->assertTrue(Hash::check(config('security.temporary_user_password'), $target->password));
        $this->assertTrue($target->must_change_password);
        $this->assertNotNull($target->ssoIdentity);
        foreach (['user.password_reset_requested', 'user.password_reset_completed', 'user.local_login_enabled'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'subject_id' => $target->id]);
        }
        Auth::logout();
        $this->post('/login', ['email' => $target->email, 'password' => config('security.temporary_user_password')])->assertRedirect(route('password.force-change'));
        $this->assertAuthenticatedAs($target);
    }

    public function test_failed_admin_reset_rolls_back_hash_and_enablement(): void
    {
        $target = $this->ssoOnly();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andReturnUsing(function ($event, $metadata = [], $subject = null, $request = null, $result = null) {
            if ($event === 'user.local_login_enabled') {
                throw new \RuntimeException('Audit failure');
            }

            return (new AuditLogger)->record($event, $metadata, $subject, $request, $result);
        });
        $this->patch('/admin/users/'.$target->id.'/reset-password')->assertServerError();
        $this->assertFalse($target->fresh()->local_login_enabled);
        $this->assertNull($target->fresh()->password);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.password_reset_completed']);
    }

    public function test_unauthorized_or_unconfigured_reset_cannot_enable_login(): void
    {
        $target = $this->ssoOnly();
        $this->actingAs($target)->patch('/admin/users/'.$target->id.'/reset-password')->assertForbidden();
        $this->actingAs($this->admin);
        config(['security.temporary_user_password' => '']);
        $this->patch('/admin/users/'.$target->id.'/reset-password')->assertSessionHasErrors('temporary_password');
        $this->assertFalse($target->fresh()->local_login_enabled);
        $this->assertNull($target->fresh()->password);
    }

    public function test_unlink_does_not_create_a_password_or_enable_local_login(): void
    {
        $target = $this->ssoOnly();
        $identity = UserSsoIdentity::create(['user_id' => $target->id, 'issuer' => self::ISSUER, 'subject' => 'institutional-subject']);
        $this->delete('/admin/users/'.$target->id.'/sso-identity', ['identity_id' => $identity->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->local_login_enabled);
        $this->assertNull($target->fresh()->password);
        $this->assertNull($target->fresh()->ssoIdentity);
    }

    public function test_public_reset_routes_do_not_exist_and_forged_tokens_do_not_enable_login(): void
    {
        $target = $this->ssoOnly();
        Auth::logout();
        $this->post('/forgot-password', ['email' => $target->email])->assertNotFound();
        $this->post('/reset-password', ['email' => $target->email, 'token' => 'forged', 'password' => 'New.Password123!', 'password_confirmation' => 'New.Password123!'])->assertNotFound();
        $this->assertFalse($target->fresh()->local_login_enabled);
        $this->assertNull($target->fresh()->password);
    }

    public function test_policy_migration_preserves_existing_accounts_and_has_a_safe_rollback(): void
    {
        $before = $this->target->password;
        $migration = require base_path('database/migrations/2026_10_24_000001_add_local_login_policy_to_users.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'local_login_enabled'));
        $migration->up();
        $this->assertTrue($this->target->fresh()->local_login_enabled);
        $this->assertSame($before, $this->target->fresh()->password);
        $this->ssoOnly();
        try {
            $migration->down();
            $this->fail('Rollback cannot erase SSO-only policy');
        } catch (\RuntimeException) {
            $this->assertTrue(Schema::hasColumn('users', 'local_login_enabled'));
            $this->assertNull($this->target->fresh()->password);
        }
    }
}
