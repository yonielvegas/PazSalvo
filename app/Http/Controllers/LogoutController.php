<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

final class LogoutController extends Controller
{
    public function __invoke(Request $request, AuthenticatedSessionController $fortify): mixed
    {
        // Capture before Fortify flushes the session. Never infer the method from the account.
        $portal = $request->session()->get('auth_method') === 'oidc' ? $this->portalUrl() : null;
        $response = $fortify->destroy($request); // guard logout, server-side invalidation and new CSRF token

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $response; // Preserve Fortify's JSON logout contract (204).
        }

        return ($portal ? Inertia::location($portal) : redirect()->route('login'))
            ->header('Cache-Control', 'no-store, private');
    }

    private function portalUrl(): ?string
    {
        $url = config('aaud-oauth.portal_url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '/portal') {
            return null;
        }
        $localHttp = ($parts['scheme'] ?? '') === 'http' && app()->environment(['local', 'testing'])
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);

        return ($parts['scheme'] ?? '') === 'https' || $localHttp ? $url : null;
    }
}
