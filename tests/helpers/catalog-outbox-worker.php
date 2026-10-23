<?php

use App\Integrations\AaudAuth\Exceptions\CatalogPersistenceException;
use App\Integrations\AaudAuth\Http\RoleCatalogResult;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_ends_with((string) config('database.connections.pgsql.database'), '_testing')
    || DB::selectOne('select current_database() as name')->name !== config('database.connections.pgsql.database')) {
    throw new RuntimeException('An effectively isolated testing database is required');
}
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('Persistence worker must never call HTTP'));
[$script, $mode, $id, $ready, $release] = $argv;
$detail = $argv[5] ?? '';
file_put_contents($ready.'.started', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
$barrier = function () use ($ready, $release) {
    file_put_contents($ready, 'locked');
    $deadline = microtime(true) + 15;
    while ($release !== '-' && ! is_file($release)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Testing barrier timed out');
        }
        usleep(10000);
    }
};
try {
    if ($mode === 'inspect') {
        $operation = app(CatalogOutbox::class)->get((int) $id);
        echo json_encode(['status' => $operation->status, 'payload' => $operation->payload(), 'hash' => $operation->payloadHash], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($mode === 'raw_insert') {
        DB::transaction(function () use ($id, $barrier) {
            $uuid = (string) Str::uuid();
            DB::table('aaud_auth_catalog_operations')->insert([
                'application_code' => 'pazysalvo', 'resource' => 'roles', 'version' => (int) $id, 'operation_id' => $uuid,
                'payload' => json_encode(['version' => (int) $id, 'operation_id' => $uuid,
                    'roles' => [['code' => 'test', 'name' => 'Test', 'active' => true]]], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $barrier();
        });
        echo json_encode(['result' => 'committed']);
        exit;
    }
    $operation = DB::transaction(function () use ($mode, $id, $barrier, $detail) {
        DB::select('SELECT pg_advisory_xact_lock(17012026, 1)');
        $barrier();
        $outbox = app(CatalogOutbox::class);
        if ($mode === 'claim') {
            $operation = $outbox->claim((int) $id);
        } elseif ($mode === 'confirm') {
            $stored = $outbox->get((int) $id);
            $receipt = new RoleCatalogResult('applied', $stored->version, $stored->operationId, $stored->version, false,
                array_map(fn ($role) => [...$role, 'name' => trim($role['name']), 'description' => null], $stored->payload()['roles']));
            $operation = $outbox->confirm((int) $id, $detail, $receipt);
        } elseif ($mode === 'cancel') {
            $operation = $outbox->cancelAfterReview((int) $id, (int) $detail, 'CONCURRENT-RECONCILIATION', User::role('admin')->firstOrFail());
        } elseif ($mode === 'recover') {
            $operation = $outbox->recoverExpiredClaim((int) $id);
        } else {
            $operation = $outbox->prepare();
        }
        if ($mode === 'rollback_prepare') {
            throw new RuntimeException('intentional_testing_rollback');
        }

        return $operation;
    });
    echo json_encode(['result' => 'committed', 'id' => $operation->id, 'version' => $operation->version,
        'operation_id' => $operation->operationId, 'claim_id' => $operation->claimId], JSON_THROW_ON_ERROR);
} catch (CatalogPersistenceException $exception) {
    echo json_encode(['result' => 'rejected', 'reason' => $exception->reason], JSON_THROW_ON_ERROR);
} catch (QueryException $exception) {
    if ($exception->getCode() !== '23505') {
        throw $exception;
    }
    echo json_encode(['result' => 'rejected', 'reason' => 'unique_constraint']);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'intentional_testing_rollback') {
        throw $exception;
    }
    echo json_encode(['result' => 'rolled_back']);
}
