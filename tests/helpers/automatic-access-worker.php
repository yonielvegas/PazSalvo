<?php

use App\Integrations\AaudAuth\Access\AccessDelivery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('app.env') !== 'testing' || ! str_ends_with(DB::selectOne('SELECT current_database() AS name')->name, '_testing')) {
    throw new RuntimeException('Isolated PostgreSQL testing required');
}
[$script, $owner, $marker, $release] = $argv;
config(['aaud-auth.automatic' => true, 'aaud-auth.enabled' => true, 'aaud-auth.issuer' => 'https://id.example.test/realms/aaud',
    'aaud-auth.api_url' => 'https://auth.example.test', 'aaud-auth.token_url' => 'https://id.example.test/token',
    'aaud-auth.client_id' => 'pazysalvo-sync', 'aaud-auth.client_secret' => 'fixture-private']);
file_put_contents($marker.'.started', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
Http::preventStrayRequests();
$sent = 0;
$current = null;
Http::fake(function ($request) use ($marker, $release, &$sent, &$current) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('HTTP under transaction');
    }
    if (str_ends_with($request->url(), '/token')) {
        return Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'access.write roles.write']);
    }
    if ($request->method() === 'GET') {
        file_put_contents($marker, 'GET');
        $deadline = microtime(true) + 15;
        while ($release !== '-' && ! is_file($release) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if ($release !== '-' && ! is_file($release)) {
            throw new RuntimeException('Barrier timeout');
        }

        return Http::response(['data' => $current ?? ['issuer' => config('aaud-auth.issuer'), 'subject' => 'subject-A', 'application_code' => 'pazysalvo',
            'application_user_id' => null, 'exists' => false, 'is_active' => false, 'roles' => [], 'version' => 0]]);
    }
    $sent++;
    $data = $request->data() + ['subject' => 'subject-A'];
    $current = array_intersect_key($data, array_flip(['issuer', 'subject', 'application_user_id', 'is_active', 'roles', 'version'])) + ['application_code' => 'pazysalvo', 'exists' => true];

    return Http::response(['status' => 'applied', 'data' => $data + ['application_code' => 'pazysalvo', 'confirmed_at' => now()->toIso8601String()],
        'current_version' => $data['version'], 'historical' => false]);
});
app(AccessDelivery::class)->deliver($owner);
echo json_encode(['puts' => $sent], JSON_THROW_ON_ERROR);
