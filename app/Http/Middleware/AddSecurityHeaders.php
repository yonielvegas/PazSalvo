<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', $request->is('auth/aaud/*') ? 'no-referrer' : 'strict-origin-when-cross-origin');
        if ($request->is('auth/aaud/*')) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('Pragma', 'no-cache');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! $request->is('verificar', 'verificar/*', 'validar-paz-salvo')) {
            $headers->set('Content-Security-Policy', "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-src 'self'");
        }

        if (app()->environment('production') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is('verificar', 'verificar/*', 'validar-paz-salvo')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->user()) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
