<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class AttemptToAuthenticateWithSpanishMessages
{
    public function __construct(
        protected StatefulGuard $guard,
        protected LoginRateLimiter $limiter,
    ) {}

    public function handle($request, $next)
    {
        $email = strtolower((string) $request->input(Fortify::username()));
        $user = User::where('email', $email)->first();

        if ($user?->is_login_blocked) {
            $this->throwBlocked();
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

            $this->guard->login($user, $request->boolean('remember'));
            $request->session()->put('auth_session_version', $user->session_version);
            $request->session()->put('authenticated_session_started_at', now()->timestamp);
            $request->session()->put('session_regenerated_at', now()->timestamp);
            $request->session()->regenerateToken();
            app(AuditLogger::class)->record('login.succeeded', ['email' => $email], $user, $request, 'success');

            return $next($request);
        }

        if ($user && $user->is_active) {
            $attempts = min(3, ((int) $user->login_attempts) + 1);
            $isBlocked = $attempts >= 3;
            $user->forceFill([
                'login_attempts' => $attempts,
                'is_login_blocked' => $isBlocked,
            ])->save();

            if ($isBlocked) {
                app(AuditLogger::class)->record('login.failed', ['email' => $email, 'blocked' => true], $user, $request, 'blocked');
                $this->throwBlocked();
            }

            $remaining = 3 - $attempts;
            $suffix = $remaining === 1 ? 'Le queda 1 intento antes del bloqueo.' : "Le quedan {$remaining} intentos antes del bloqueo.";

            throw ValidationException::withMessages([
                Fortify::username() => ["Correo o contraseña incorrectos. {$suffix}"],
            ]);
        }

        $this->limiter->increment($request);
        app(AuditLogger::class)->record('login.failed', ['email' => $email], $user, $request, 'failed');

        throw ValidationException::withMessages([
            Fortify::username() => ['Correo o contraseña incorrectos.'],
        ]);
    }

    private function throwBlocked(): void
    {
        throw ValidationException::withMessages([
            Fortify::username() => ['Su cuenta fue bloqueada por intentos fallidos. Contacte a un administrador para desbloquearla.'],
        ]);
    }
}
