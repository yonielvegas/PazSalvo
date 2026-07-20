<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function manage(User $user): bool
    {
        return $user->can('administrar usuarios');
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $user->can('administrar usuarios');
    }
}
