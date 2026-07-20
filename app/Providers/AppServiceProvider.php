<?php

namespace App\Providers;

use App\Models\PazSalvo;
use App\Models\User;
use App\Policies\PazSalvoPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(PazSalvo::class, PazSalvoPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::define('manage-users', fn ($user) => $user->can('administrar usuarios'));
        Gate::define('manage-roles', fn ($user) => $user->can('administrar roles'));

        if ($this->app->environment('production')) {
            $missing = [];
            foreach ([
                'APP_KEY' => config('app.key'),
                'PUBLIC_VERIFICATION_BASE_URL' => config('paz_salvo.public_verification_base_url'),
                'APP_ALLOWED_HOSTS' => config('security.allowed_hosts'),
                'INTERNAL_ALLOWED_CIDRS' => config('security.internal_network.allowed_ips'),
                'USER_TEMPORARY_PASSWORD' => config('security.temporary_user_password'),
            ] as $name => $value) {
                if ($value === null || $value === '' || $value === []) {
                    $missing[] = $name;
                }
            }

            if (config('app.debug') || ! config('session.secure')) {
                $missing[] = 'APP_DEBUG_FALSE_AND_SECURE_COOKIES';
            }

            if ($missing !== []) {
                throw new \RuntimeException('Configuración de producción incompleta: '.implode(', ', $missing));
            }
        }
    }
}
