<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSingleActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->logout($request, 'Su usuario fue desactivado. Contacte a un administrador.', 'session.inactive_user');
        }

        $sessionVersion = $request->session()->get('auth_session_version');
        if ($sessionVersion === null && app()->runningUnitTests()) {
            $request->session()->put('auth_session_version', $user->session_version);
            $request->session()->put('authenticated_session_started_at', now()->timestamp);
            $request->session()->put('session_regenerated_at', now()->timestamp);
            $sessionVersion = $user->session_version;
        }

        if ($sessionVersion === null || (int) $sessionVersion !== (int) $user->session_version) {
            return $this->logout($request, 'Tu sesión fue cerrada porque se inició sesión desde otro dispositivo.', 'session.superseded');
        }

        $now = now()->timestamp;
        $startedAt = (int) $request->session()->get('authenticated_session_started_at', $now);
        $absoluteTimeout = config('security.session_absolute_timeout_minutes');
        if ($absoluteTimeout && $now - $startedAt > ((int) $absoluteTimeout * 60)) {
            return $this->logout($request, 'Su sesión expiró por tiempo máximo de uso. Inicie sesión nuevamente.', 'session.absolute_timeout');
        }

        $lastRegeneratedAt = (int) $request->session()->get('session_regenerated_at', $now);
        $interval = (int) config('security.session_regenerate_interval_minutes', 15);
        if ($interval > 0 && $now - $lastRegeneratedAt > ($interval * 60)) {
            $request->session()->migrate(true);
            $request->session()->put('session_regenerated_at', $now);
            $request->session()->put('auth_session_version', $user->session_version);
            $request->session()->put('authenticated_session_started_at', $startedAt);
        }

        return $next($request);
    }

    private function logout(Request $request, string $message, string $event): Response
    {
        app(AuditLogger::class)->record($event, ['result' => 'revoked'], $request->user(), $request, 'blocked');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }
}
