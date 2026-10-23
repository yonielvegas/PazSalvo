<?php

use App\Integrations\AaudAuth\Access\AccessOutbox;
use App\Integrations\AaudAuth\Access\AccessSnapshot;
use App\Integrations\AaudAuth\Exceptions\AccessPersistenceException;
use App\Integrations\AaudAuth\Http\UserAccessResult;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_ends_with((string) config('database.connections.pgsql.database'), '_testing')
    || DB::selectOne('select current_database() as name')->name !== config('database.connections.pgsql.database')) {
    throw new RuntimeException('Isolated testing database required');
}
config(['aaud-auth.issuer' => 'https://keycloak.example.test/realms/aaud']);
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('HTTP forbidden'));
[$script, $mode, $id, $actor, $ready, $release] = $argv;
file_put_contents($ready.'.started', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
$used = false;
DB::listen(function ($query) use ($ready, $release, &$used) {
    if (! $used && str_contains($query->sql, 'pg_advisory_xact_lock')) {
        $used = true;
        file_put_contents($ready, 'locked');
        $deadline = microtime(true) + 15;
        while ($release !== '-' && ! is_file($release)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Testing barrier timeout');
            }
            usleep(10000);
        }
    }
});
try {
    $outbox = app(AccessOutbox::class);
    if ($mode === 'prepare') {
        $operation = $outbox->prepare(User::findOrFail($id), User::findOrFail($actor));
    } elseif ($mode === 'claim') {
        $operation = $outbox->claim((int) $id);
    } elseif ($mode === 'recover') {
        $operation = $outbox->recoverExpiredClaim((int) $id);
    } else {
        $op = $outbox->get((int) $id);
        $p = $op->payload();
        $snapshot = AccessSnapshot::fromArray(array_diff_key($p, array_flip(['version', 'operation_id'])));
        $receipt = UserAccessResult::fromReceipt(['status' => 'applied', 'data' => $p + ['application_code' => 'pazysalvo', 'confirmed_at' => '2026-10-21T10:00:00-05:00'],
            'current_version' => $p['version'], 'historical' => false], $snapshot, $p['version'], $p['operation_id']);
        $operation = $outbox->confirm($op->id, $op->claimId ?? '', $receipt);
    }
    echo json_encode(['result' => $operation ? 'committed' : 'deferred', 'id' => $operation?->id]);
} catch (AccessPersistenceException) {
    echo json_encode(['result' => 'rejected']);
}
