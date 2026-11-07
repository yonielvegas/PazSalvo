<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/** Also protect guard-based password authentication and historical remember-me cookies. */
final class LocalLoginUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials)
    {
        return $user instanceof User && $user->local_login_enabled && parent::validateCredentials($user, $credentials);
    }

    public function retrieveByToken($identifier, #[SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user?->local_login_enabled ? $user : null;
    }
}
