<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogOutboxConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \RuntimeException('Outbox must not call HTTP'));
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        DB::table('aaud_auth_catalog_states')->insertOrIgnore([
            'application_code' => 'pazysalvo', 'resource' => 'roles', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(CatalogOutbox::class)->recordRemoteObservation(40, 'CONCURRENCY-TEST', $admin);
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function start(string $mode, int $id, string $ready, string $release, string $detail = ''): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/helpers/catalog-outbox-worker.php'), $mode, (string) $id, $ready, $release, $detail],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        return json_decode($output, true, 64, JSON_THROW_ON_ERROR);
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path);
    }

    private function clean(string $directory): void
    {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    private function race(string $firstMode, string $secondMode, int $firstId = 0, int $secondId = 0, string $expectedWait = 'advisory', string $firstDetail = '', string $secondDetail = ''): array
    {
        $dir = sys_get_temp_dir().'/catalog-outbox-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $one = $this->start($firstMode, $firstId, $dir.'/one', $dir.'/release', $firstDetail);
        $two = null;
        try {
            $this->waitForFile($dir.'/one');
            $two = $this->start($secondMode, $secondId, $dir.'/two', '-', $secondDetail);
            $this->waitForFile($dir.'/two.started');
            $firstPid = (int) file_get_contents($dir.'/one.started');
            $secondPid = (int) file_get_contents($dir.'/two.started');
            $this->assertNotSame($firstPid, $secondPid);
            $this->assertNotSame((int) DB::selectOne('select pg_backend_pid() as pid')->pid, $secondPid);
            $deadline = microtime(true) + 10;
            $blocked = false;
            do {
                $wait = DB::selectOne('select wait_event from pg_stat_activity where pid = ?', [$secondPid]);
                if (($wait->wait_event ?? null) === $expectedWait) {
                    $blocked = true;
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            touch($dir.'/release');
            $results = [$this->finish($one), $this->finish($two)];
            $this->assertTrue($blocked, 'Independent PostgreSQL connection must really wait on the first transaction');

            return $results;
        } finally {
            touch($dir.'/release');
            foreach ([$one, $two] as $worker) {
                if ($worker && is_resource($worker[0])) {
                    $this->finish($worker);
                }
            }
            $this->clean($dir);
        }
    }

    public function test_concurrent_preparations_reserve_one_version_and_reject_the_second_snapshot(): void
    {
        [$one, $two] = $this->race('prepare', 'prepare');
        $this->assertSame('committed', $one['result']);
        $this->assertSame(41, $one['version']);
        $this->assertSame(['result' => 'rejected', 'reason' => 'unresolved'], $two);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 1);
        $this->assertSame(41, app(CatalogOutbox::class)->status()['state']->last_reserved_version);
    }

    public function test_rolled_back_reservation_does_not_consume_a_version_for_the_waiting_process(): void
    {
        [$one, $two] = $this->race('rollback_prepare', 'prepare');
        $this->assertSame('rolled_back', $one['result']);
        $this->assertSame('committed', $two['result']);
        $this->assertSame(41, $two['version']);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'aaud_auth.catalog.prepared')->count());
    }

    public function test_two_processes_cannot_claim_the_same_pending_operation(): void
    {
        $operation = app(CatalogOutbox::class)->prepare();
        [$one, $two] = $this->race('claim', 'claim', $operation->id, $operation->id);
        $this->assertSame('committed', $one['result']);
        $this->assertSame(['result' => 'rejected', 'reason' => 'transition'], $two);
        $this->assertSame(1, app(CatalogOutbox::class)->get($operation->id)->attempts);
    }

    public function test_partial_unique_index_also_blocks_raw_concurrent_inserts(): void
    {
        [$one, $two] = $this->race('raw_insert', 'raw_insert', 41, 42, 'transactionid');
        $this->assertSame(['result' => 'committed'], $one);
        $this->assertSame(['result' => 'rejected', 'reason' => 'unique_constraint'], $two);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 1);
    }

    public function test_committed_operation_survives_process_exit_and_is_read_by_a_fresh_process(): void
    {
        $dir = sys_get_temp_dir().'/catalog-outbox-restart-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        try {
            $prepared = $this->finish($this->start('prepare', 0, $dir.'/prepared', '-'));
            $this->assertSame('committed', $prepared['result']);
            $expected = app(CatalogOutbox::class)->get($prepared['id']);
            $restarted = $this->finish($this->start('inspect', $prepared['id'], $dir.'/restarted', '-'));
            $this->assertSame('pending', $restarted['status']);
            $this->assertSame($expected->payload(), $restarted['payload']);
            $this->assertSame($expected->payloadHash, $restarted['hash']);
        } finally {
            $this->clean($dir);
        }
    }

    public function test_two_processes_cannot_confirm_the_same_claim_twice(): void
    {
        $outbox = app(CatalogOutbox::class);
        $operation = $outbox->prepare();
        $claim = $outbox->claim($operation->id);
        [$one, $two] = $this->race('confirm', 'confirm', $operation->id, $operation->id,
            firstDetail: $claim->claimId, secondDetail: $claim->claimId);
        $this->assertSame('committed', $one['result']);
        $this->assertSame(['result' => 'rejected', 'reason' => 'claim'], $two);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'aaud_auth.catalog.confirmed')->count());
    }

    public function test_old_claim_cannot_confirm_after_an_independent_process_reclaims_the_operation(): void
    {
        $outbox = app(CatalogOutbox::class);
        $operation = $outbox->prepare();
        $claim = $outbox->claim($operation->id, 1);
        usleep(1100000);
        $outbox->recoverExpiredClaim($operation->id);
        [$one, $two] = $this->race('claim', 'confirm', $operation->id, $operation->id, secondDetail: $claim->claimId);
        $this->assertSame('committed', $one['result']);
        $this->assertNotSame($claim->claimId, $one['claim_id']);
        $this->assertSame(['result' => 'rejected', 'reason' => 'claim'], $two);
        $this->assertSame('processing', $outbox->get($operation->id)->status);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'aaud_auth.catalog.confirmed']);
    }

    public function test_expired_claim_can_only_be_recovered_once_by_concurrent_processes(): void
    {
        $outbox = app(CatalogOutbox::class);
        $operation = $outbox->prepare();
        $outbox->claim($operation->id, 1);
        usleep(1100000);
        [$one, $two] = $this->race('recover', 'recover', $operation->id, $operation->id);
        $this->assertSame('committed', $one['result']);
        $this->assertSame(['result' => 'rejected', 'reason' => 'transition'], $two);
        $this->assertSame('uncertain', $outbox->get($operation->id)->status);
    }

    public function test_concurrent_cancellations_record_one_resolution_and_allow_a_new_version(): void
    {
        $outbox = app(CatalogOutbox::class);
        $operation = $outbox->prepare();
        $claim = $outbox->claim($operation->id);
        $outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('forbidden'));
        [$one, $two] = $this->race('cancel', 'cancel', $operation->id, $operation->id, firstDetail: '40', secondDetail: '40');
        $this->assertSame('committed', $one['result']);
        $this->assertSame(['result' => 'rejected', 'reason' => 'transition'], $two);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'aaud_auth.catalog.cancelled')->count());
        $this->assertSame(42, $outbox->prepare()->version);
    }

    public function test_cancellation_and_new_preparation_are_serialized_without_reusing_the_cancelled_version(): void
    {
        $outbox = app(CatalogOutbox::class);
        $operation = $outbox->prepare();
        $claim = $outbox->claim($operation->id);
        $outbox->recordFailure($operation->id, $claim->claimId, new AaudAuthRequestException('invalid_request'));
        [$one, $two] = $this->race('cancel', 'prepare', $operation->id, firstDetail: '40');
        $this->assertSame('committed', $one['result']);
        $this->assertSame('committed', $two['result']);
        $this->assertSame(42, $two['version']);
        $this->assertSame('cancelled', $outbox->get($operation->id)->status);
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 2);
    }
}
