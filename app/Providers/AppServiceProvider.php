<?php

namespace App\Providers;

use App\Models\PazSalvo;
use App\Models\User;
use App\Policies\PazSalvoPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('public-manual-validation', function (Request $request) {
            return Limit::perMinute((int) config('public.rate_limits.manual'))
                ->by('manual:'.$request->ip())
                ->response(fn () => response('Demasiados intentos. Intente nuevamente más tarde.', 429));
        });

        RateLimiter::for('public-qr-validation', function (Request $request) {
            return Limit::perMinute((int) config('public.rate_limits.qr'))
                ->by('qr:'.$request->ip())
                ->response(fn () => response('Demasiadas solicitudes. Intente nuevamente más tarde.', 429));
        });

        RateLimiter::for('public-pdf', function (Request $request) {
            $token = (string) $request->route('token', '');
            $tokenHash = $token === '' ? 'missing' : Str::substr(hash('sha256', $token), 0, 16);

            return Limit::perMinute((int) config('public.rate_limits.pdf'))
                ->by('pdf:'.$request->ip().':'.$tokenHash)
                ->response(fn () => response('Demasiadas solicitudes. Intente nuevamente más tarde.', 429));
        });

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

            if (config('app.debug') !== false) {
                $missing[] = 'APP_DEBUG';
            }

            $appUrl = parse_url((string) config('app.url'));
            $scheme = is_array($appUrl) ? strtolower((string) ($appUrl['scheme'] ?? '')) : '';
            if (! in_array($scheme, ['http', 'https'], true) || empty($appUrl['host'])) {
                $missing[] = 'APP_URL';
            } elseif ($scheme === 'https' && config('session.secure') !== true) {
                $missing[] = 'SESSION_SECURE_COOKIE';
            }

            if ($missing !== []) {
                throw new \RuntimeException('Configuración de producción incompleta: '.implode(', ', $missing));
            }
        }
    }
}
