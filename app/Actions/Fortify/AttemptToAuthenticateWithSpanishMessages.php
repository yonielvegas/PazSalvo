<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
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
            if ($user->login_attempts !== 0) {
                $user->forceFill(['login_attempts' => 0])->save();
            }

            $this->guard->login($user, $request->boolean('remember'));

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
                $this->throwBlocked();
            }

            $remaining = 3 - $attempts;
            $suffix = $remaining === 1 ? 'Le queda 1 intento antes del bloqueo.' : "Le quedan {$remaining} intentos antes del bloqueo.";

            throw ValidationException::withMessages([
                Fortify::username() => ["Correo o contraseña incorrectos. {$suffix}"],
            ]);
        }

        $this->limiter->increment($request);

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
