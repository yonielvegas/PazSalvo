<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateHostHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedHosts = config('security.allowed_hosts', []);
        if ($allowedHosts === []) {
            if (app()->environment('production')) {
                abort(500, 'Configuración de hosts permitidos incompleta.');
            }

            return $next($request);
        }

        abort_unless(in_array($request->getHost(), $allowedHosts, true), 400);

        return $next($request);
    }
}
