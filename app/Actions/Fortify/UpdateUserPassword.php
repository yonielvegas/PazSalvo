<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $user->fresh()->assertLocalPasswordChangesAllowed();

        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        DB::transaction(function () use ($user, $input) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->assertLocalPasswordChangesAllowed();
            $locked->forceFill([
                'password' => Hash::make($input['password']),
                'password_changed_at' => now(),
                'session_version' => ((int) $locked->session_version) + 1,
            ])->save();
        });
        $user->refresh();

        session()->put('auth_session_version', $user->session_version);
        app(AuditLogger::class)->record('password.changed', [], $user, request(), 'success');
    }
}
