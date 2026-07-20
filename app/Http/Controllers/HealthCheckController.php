<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => true,
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'libreoffice_configured' => trim((string) config('paz-salvo.libreoffice_binary')) !== '',
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function database(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cache(): bool
    {
        try {
            Cache::put('healthcheck', '1', 5);

            return Cache::get('healthcheck') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function storage(): bool
    {
        try {
            $disk = Storage::disk(config('paz-salvo.disk'));
            $path = 'healthcheck/.probe';
            $disk->put($path, 'ok');
            $ok = $disk->exists($path);
            $disk->delete($path);

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }
}
