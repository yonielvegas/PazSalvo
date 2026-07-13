<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        foreach ($allowedRanges as $range) {
            if ($this->matches($clientIp, $range)) {
                return $next($request);
            }
        }

        abort(403, 'Acceso restringido a la red institucional.');
    }

    private function matches(?string $ip, string $range): bool
    {
        if (! $ip || $range === '') {
            return false;
        }

        if (! str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || ! is_numeric($bits)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
