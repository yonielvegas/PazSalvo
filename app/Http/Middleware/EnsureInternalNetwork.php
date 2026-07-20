<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalNetwork
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.internal_network.enabled')) {
            return $next($request);
        }

        $clientIp = $request->ip();
        $allowedRanges = config('security.internal_network.allowed_ips', []);
        if ($allowedRanges === [] && app()->environment('production')) {
            Log::critical('Internal network restriction enabled without allowed CIDRs.');
            abort(503, 'Acceso institucional no configurado.');
        }

        foreach ($allowedRanges as $range) {
            if ($this->matches($clientIp, $range)) {
                return $next($request);
            }
        }

        Log::warning('Institutional network access rejected.', [
            'ip' => $clientIp,
            'path' => $request->path(),
        ]);

        abort(403, 'Acceso restringido a la red institucional.');
    }

    private function matches(?string $ip, string $range): bool
    {
        if (! $ip || $range === '') {
            return false;
        }

        return IpUtils::checkIp($ip, $range);
    }
}
