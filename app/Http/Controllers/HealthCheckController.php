<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => true,
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'libreoffice' => $this->libreOffice(),
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
        $key = 'healthcheck:'.Str::uuid();

        try {
            Cache::put($key, '1', 5);
            $ok = Cache::get($key) === '1';
            Cache::forget($key);

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    private function storage(): bool
    {
        $path = 'healthcheck/'.Str::uuid().'.probe';

        try {
            $disk = Storage::disk(config('paz-salvo.disk'));
            $disk->put($path, 'ok');
            $ok = $disk->exists($path);
            $disk->delete($path);

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    private function libreOffice(): bool
    {
        $binary = trim((string) config('paz-salvo.libreoffice_binary'));

        if ($binary === '') {
            return false;
        }

        $resolved = str_contains($binary, DIRECTORY_SEPARATOR)
            ? $binary
            : (new ExecutableFinder)->find($binary);

        return $resolved !== null && is_executable($resolved);
    }
}
