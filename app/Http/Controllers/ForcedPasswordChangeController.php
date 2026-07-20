<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ForcedPasswordChangeController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()?->must_change_password) {
            return redirect()->to($this->homePath($request->user()));
        }

        return Inertia::render('auth/forced-password-change');
    }

    public function update(Request $request, AuditLogger $audit): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! $user->must_change_password) {
            return redirect()->to($this->homePath($user));
        }

        $temporaryPassword = $this->temporaryPassword();

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
                function (string $attribute, mixed $value, \Closure $fail) use ($temporaryPassword): void {
                    if (hash_equals($temporaryPassword, (string) $value)) {
                        $fail('No puede utilizar la contraseña temporal como contraseña definitiva.');
                    }
                },
            ],
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.mixed' => 'Incluya al menos una letra mayúscula y una letra minúscula.',
            'password.letters' => 'Incluya al menos una letra.',
            'password.numbers' => 'Incluya al menos un número.',
            'password.symbols' => 'Incluya al menos un carácter especial.',
        ]);

        $newVersion = DB::transaction(function () use ($user, $validated) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $newVersion = ((int) $locked->session_version) + 1;

            $locked->forceFill([
                'password' => Hash::make($validated['password']),
                'must_change_password' => false,
                'password_changed_at' => now(),
                'password_reset_at' => null,
                'password_reset_by' => null,
                'login_attempts' => 0,
                'is_login_blocked' => false,
                'session_version' => $newVersion,
                'remember_token' => null,
            ])->save();

            DB::table('sessions')
                ->where('user_id', $locked->id)
                ->where('id', '!=', request()->session()->getId())
                ->delete();

            return $newVersion;
        });

        $request->session()->put('auth_session_version', $newVersion);
        $request->session()->put('authenticated_session_started_at', now()->timestamp);
        $request->session()->put('session_regenerated_at', now()->timestamp);
        $request->session()->migrate(true);
        $request->session()->regenerateToken();

        $audit->record('user.forced_password_changed', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ], $user, $request, 'success');

        $homePath = $this->homePath($user->fresh());

        if ($request->header('X-Inertia')) {
            return Inertia::render('auth/forced-password-change', [
                'changed' => true,
                'redirect_to' => $homePath,
            ]);
        }

        return redirect()->to($homePath)->with('message', 'Contraseña actualizada correctamente.');
    }

    private function temporaryPassword(): string
    {
        $temporaryPassword = (string) config('security.temporary_user_password');

        if ($temporaryPassword === '') {
            throw ValidationException::withMessages([
                'password' => 'La contraseña temporal no está configurada. Contacte a un administrador.',
            ]);
        }

        return $temporaryPassword;
    }

    private function homePath(?User $user): string
    {
        if (! $user) {
            return route('login');
        }

        return match (true) {
            $user->can('consultar paz y salvo') => route('paz-salvo.index'),
            $user->can('ver historial') => route('paz-salvos.index'),
            $user->can('administrar usuarios') => route('users.index'),
            $user->can('administrar roles') => route('admin.roles.index'),
            default => route('institutional.access'),
        };
    }
}
