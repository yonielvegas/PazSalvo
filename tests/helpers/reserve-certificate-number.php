<?php

use App\Services\CertificateNumberService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$schema = getenv('TEST_SEQUENCE_SCHEMA');
if (! app()->environment('testing') || ! preg_match('/^sequence_test_[a-f0-9]+$/', $schema)
    || ! str_ends_with((string) config('database.connections.pgsql.database'), '_testing')) {
    throw new RuntimeException('Concurrency fixtures require an isolated testing database/schema.');
}
config(['database.connections.pgsql.search_path' => $schema]);
DB::purge('pgsql');
echo "started\n";
DB::beginTransaction();
try {
    $sequence = app(CertificateNumberService::class)->reserve(2026);
    echo 'reserved:'.$sequence['number']."\n";
    if ($barrier = getenv('TEST_SEQUENCE_BARRIER')) {
        $deadline = microtime(true) + 10;
        while (! is_file($barrier)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for concurrency barrier.');
            }
            usleep(10000);
        }
    }
    if (getenv('TEST_SEQUENCE_ROLLBACK') === '1') {
        DB::rollBack();
    } else {
        DB::commit();
    }
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    throw $exception;
}
