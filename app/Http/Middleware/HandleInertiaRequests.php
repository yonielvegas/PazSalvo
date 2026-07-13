<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('agency');

        return [...parent::share($request), 'auth' => ['user' => $user ? [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'agency' => $user->agency?->only(['id', 'name']), 'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ] : null], 'flash' => [
            'result' => fn () => $request->session()->get('result'),
            'document' => fn () => $request->session()->get('document'),
            'message' => fn () => $request->session()->get('message'),
            'error' => fn () => $request->session()->get('error'),
            'validation_not_found' => fn () => $request->session()->get('validation_not_found'),
            'validation_not_found_id' => fn () => $request->session()->get('validation_not_found_id'),
        ]];
    }
}
