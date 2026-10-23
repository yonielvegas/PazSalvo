<?php

namespace App\Http\Controllers;

use App\Integrations\AaudAuth\Oidc\OidcAuthenticationException;
use App\Integrations\AaudAuth\Oidc\OidcClient;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Services\AdministrativeTransaction;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class OidcLoginController extends Controller
{
    public function redirect(Request $request, OidcClient $client): RedirectResponse
    {
        try {
            $config = $client->configuration();
            $flow = ['state' => bin2hex(random_bytes(32)), 'nonce' => bin2hex(random_bytes(32)),
                'verifier' => rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '='), 'issued_at' => time(),
                'issuer' => $config['issuer'], 'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri']];
            $url = $client->authorizationUrl($flow);
            $request->session()->put('oidc_flow', $flow);

            return redirect()->away($url);
        } catch (Throwable) {
            return $this->denied($request, 'unavailable');
        }
    }

    public function callback(Request $request, OidcClient $client): RedirectResponse
    {
        try {
            $flow = $request->session()->pull('oidc_flow');
            // Persist consumption before exchanging a code, even if the provider fails later.
            $request->session()->save();
            $state = $request->query('state');
            $code = $request->query('code');
            if (! is_array($flow) || ! is_string($state) || ! is_string($flow['state'] ?? null)
                || ! hash_equals($flow['state'], $state) || ! is_int($flow['issued_at'] ?? null)
                || $flow['issued_at'] > time() || time() - $flow['issued_at'] > 300
                || $request->query->has('error') || ! is_string($code) || $code === '' || strlen($code) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $code)
                || ($request->query->has('iss') && $request->query('iss') !== $flow['issuer'])
                || ! Cache::add('oidc:consumed:'.hash('sha256', $state), true, 600)) {
                throw new OidcAuthenticationException('callback');
            }
            $identity = $client->identity($flow, $code);
            $association = UserSsoIdentity::where('issuer', $identity['issuer'])->where('subject', $identity['subject'])->first();
            if (! $association) {
                throw new OidcAuthenticationException('access_denied');
            }
            [$user, $link] = AdministrativeTransaction::run(function () use ($association, $identity, $request) {
                // Existing administration lock order: advisory lock -> user -> identity.
                $user = User::whereKey($association->user_id)->lockForUpdate()->first();
                $link = UserSsoIdentity::whereKey($association->id)->where('user_id', $user?->id)
                    ->where('issuer', $identity['issuer'])->where('subject', $identity['subject'])->lockForUpdate()->first();
                if (! $user || ! $link || ! $user->is_active || $user->roles()->count() !== 1
                    || ! $user->roles()->where('guard_name', 'web')->where('is_active', true)->exists()) {
                    throw new OidcAuthenticationException('access_denied');
                }
                // Do not reset failed-password attempts, change the local password or import token roles.
                $user->forceFill(['session_version' => (int) $user->session_version + 1])->save();
                app(AuditLogger::class)->record('login.sso.succeeded', ['identity_id' => $link->id, 'authentication_method' => 'oidc'], $user, $request, 'success');

                return [$user, $link];
            });
            Auth::guard('web')->login($user, false);
            $request->session()->regenerate();
            $request->session()->put(['auth_method' => 'oidc', 'auth_sso_identity_id' => $link->id,
                'auth_sso_issuer' => $link->issuer, 'auth_sso_subject' => $link->subject,
                'auth_session_version' => $user->session_version, 'authenticated_session_started_at' => time(),
                'session_regenerated_at' => time(), 'last_authenticated_activity_at' => time()]);
            $request->session()->forget('url.intended');

            // Fixed local landing; never trust a target/returnTo URL from the browser or provider.
            return redirect()->route('paz-salvo.index');
        } catch (Throwable) {
            return $this->denied($request, 'access_denied');
        }
    }

    private function denied(Request $request, string $reason): RedirectResponse
    {
        $request->session()->forget('oidc_flow');
        try {
            app(AuditLogger::class)->record('login.sso.failed', ['reason' => $reason], request: $request, result: 'failed');
        } catch (Throwable) {
            // Failing closed must not log a callback URL, code, token or provider exception.
        }

        return redirect()->route('login')->with('error', 'No se pudo iniciar sesión con AAUD AUTH. Verifique su autorización o intente nuevamente.');
    }
}
