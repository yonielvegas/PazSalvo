<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Access\AccessOutbox;
use App\Integrations\AaudAuth\Access\AccessSnapshotBuilder;
use App\Models\User;
use App\Models\UserSsoIdentity;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessOutboxConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    public static function races(): array
    {
        return [['prepare'], ['claim'], ['confirm'], ['recover']];
    }

    #[DataProvider('races')]
    public function test_independent_processes_serialize_versions_claims_receipts_and_recovery(string $mode): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('HTTP forbidden'));
        config(['aaud-auth.issuer' => 'https://keycloak.example.test/realms/aaud']);
        $this->seed(MasterDataSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $user = User::factory()->create();
        $user->assignRole('operador');
        UserSsoIdentity::create(['user_id' => $user->id, 'issuer' => config('aaud-auth.issuer'), 'subject' => 'concurrent-subject']);
        $outbox = app(AccessOutbox::class);
        $old = app(AccessSnapshotBuilder::class)->build($user)->payload();
        $outbox->recordRemoteObservation(app(AccessSnapshotBuilder::class)->revocation($old), 0, true, 'TEST', $actor);
        $id = $user->id;
        if ($mode !== 'prepare') {
            $op = $outbox->prepare($user, $actor);
            $id = $op->id;
            if (in_array($mode, ['confirm', 'recover'])) {
                $outbox->claim($id, $mode === 'recover' ? 1 : 60);
                if ($mode === 'recover') {
                    usleep(1100000);
                }
            }
        }
        $dir = sys_get_temp_dir().'/access-race-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $workers = [];
        $start = function (string $name, string $release) use ($mode, $id, $actor, $dir, &$workers) {
            $p = proc_open([PHP_BINARY, base_path('tests/helpers/access-outbox-worker.php'), $mode, (string) $id, (string) $actor->id, $dir.'/'.$name, $release],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($p);
            $workers[] = [$p, $pipes];
        };
        $await = function (callable $condition): void {
            $deadline = microtime(true) + 10;
            while (! $condition() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertTrue($condition());
        };
        try {
            $start('one', $dir.'/release');
            $await(fn () => is_file($dir.'/one'));
            $start('two', '-');
            $await(fn () => is_file($dir.'/two.started'));
            $pid = (int) file_get_contents($dir.'/two.started');
            $this->assertNotSame((int) file_get_contents($dir.'/one.started'), $pid);
            $firstPid = (int) file_get_contents($dir.'/one.started');
            $await(fn () => (bool) DB::selectOne('SELECT ?::int = ANY(pg_blocking_pids(?::int)) AS blocked', [$firstPid, $pid])->blocked);
            file_put_contents($dir.'/release', 'go');
            $results = [];
            foreach ($workers as [$p,$pipes]) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($p), $stderr);
                $results[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['result'];
            }
            $this->assertSame(['committed', $mode === 'prepare' ? 'deferred' : 'rejected'], $results);
            $this->assertDatabaseCount('aaud_auth_access_operations', 1);
            $this->assertSame(1, (int) DB::table('aaud_auth_access_states')->value('last_reserved_version'));
            Http::assertNothingSent();
        } finally {
            file_put_contents($dir.'/release', 'go');
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
