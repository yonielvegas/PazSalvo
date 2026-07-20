<?php

namespace App\Policies;

use App\Models\PazSalvo;
use App\Models\User;

class PazSalvoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ver historial');
    }

    public function view(User $user, PazSalvo $pazSalvo): bool
    {
        return $user->can('ver detalle paz y salvo');
    }

    public function download(User $user, PazSalvo $pazSalvo): bool
    {
        return $user->can('ver detalle paz y salvo');
    }

    public function generate(User $user): bool
    {
        return $user->can('generar paz y salvo');
    }

    public function cancel(User $user, PazSalvo $pazSalvo): bool
    {
        return $user->can('anular paz y salvo');
    }
}
