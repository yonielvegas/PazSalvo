<?php

namespace App\Providers;

use App\Actions\Fortify\AttemptToAuthenticateWithSpanishMessages;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Fortify::ignoreRoutes();
        $this->app->singleton(LoginResponse::class, fn () => new class implements LoginResponse
        {
            public function toResponse($request)
            {
                if ($request->user()?->must_change_password) {
                    return redirect()->route('password.force-change');
                }

                return redirect()->intended(config('fortify.home'));
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => Inertia::render('auth/login'));
        Fortify::authenticateThrough(fn (Request $request) => array_filter([
            EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            AttemptToAuthenticateWithSpanishMessages::class,
            PrepareAuthenticatedSession::class,
        ]));
        Fortify::authenticateUsing(function (Request $request) {
            $email = strtolower((string) $request->input(Fortify::username()));
            $user = User::where('email', $email)->first();

            if ($user?->is_login_blocked) {
                throw ValidationException::withMessages([
                    Fortify::username() => ['Su cuenta fue bloqueada por intentos fallidos. Contacte a un administrador para desbloquearla.'],
                ]);
            }

            if ($user && $user->is_active && Hash::check((string) $request->input('password'), $user->password)) {
                $user = DB::transaction(function () use ($user) {
                    $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $locked->forceFill([
                        'login_attempts' => 0,
                        'is_login_blocked' => false,
                        'session_version' => ((int) $locked->session_version) + 1,
                    ])->save();

                    return $locked;
                });
                $request->session()->put('auth_session_version', $user->session_version);
                $request->session()->put('authenticated_session_started_at', now()->timestamp);
                $request->session()->put('session_regenerated_at', now()->timestamp);
                app(AuditLogger::class)->record('login.succeeded', ['email' => $email], $user, $request, 'success');

                return $user;
            }

            if ($user && $user->is_active) {
                $attempts = min(3, ((int) $user->login_attempts) + 1);
                $user->forceFill([
                    'login_attempts' => $attempts,
                    'is_login_blocked' => $attempts >= 3,
                ])->save();

                if ($attempts >= 3) {
                    throw ValidationException::withMessages([
                        Fortify::username() => ['Su cuenta fue bloqueada por intentos fallidos. Contacte a un administrador para desbloquearla.'],
                    ]);
                }

                $remaining = 3 - $attempts;
                $suffix = $remaining === 1 ? 'Le queda 1 intento antes del bloqueo.' : "Le quedan {$remaining} intentos antes del bloqueo.";
                throw ValidationException::withMessages([
                    Fortify::username() => ["Correo o contraseña incorrectos. {$suffix}"],
                ]);
            }

            throw ValidationException::withMessages([
                Fortify::username() => ['Correo o contraseña incorrectos.'],
            ]);
        });
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

    }
}
