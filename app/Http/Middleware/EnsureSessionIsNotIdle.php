<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSessionIsNotIdle
{
    public function handle(Request $request, Closure $next): Response
    {
        $timeout = (int) config('security.session_idle_timeout_minutes', 15);
        if ($timeout <= 0 || ! Auth::check()) {
            return $next($request);
        }

        $lastActivity = (int) $request->session()->get('last_authenticated_activity_at', now()->timestamp);
        if (now()->timestamp - $lastActivity > ($timeout * 60)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Su sesión expiró por inactividad. Inicie sesión nuevamente.');
        }

        $request->session()->put('last_authenticated_activity_at', now()->timestamp);

        return $next($request);
    }
}
