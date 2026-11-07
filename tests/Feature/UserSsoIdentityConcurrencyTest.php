<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserSsoIdentity;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserSsoIdentityConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    public static function races(): array
    {
        return ['same user via service' => ['link', true, 'advisory'], 'same identity via service' => ['link', false, 'advisory'],
            'same user raw constraint' => ['raw', true, 'transactionid'], 'same identity raw constraint' => ['raw', false, 'transactionid'],
            'concurrent unlink' => ['unlink', true, 'advisory'],
            'concurrent creations share one identity' => ['create', false, 'advisory'],
            'raw username uniqueness' => ['username', false, 'transactionid']];
    }

    #[DataProvider('races')]
    public function test_concurrent_mutations_use_independent_postgres_connections(string $operation, bool $sameUser, string $waitEvent): void
    {
        Http::preventStrayRequests();
        $role = Role::create(['name' => 'operador', 'guard_name' => 'web']);
        $actor = User::factory()->create();
        $actor->assignRole($role);
        $actor->givePermissionTo(Permission::create(['name' => 'administrar usuarios', 'guard_name' => 'web']));
        $one = User::factory()->create();
        $one->assignRole($role);
        $two = $sameUser ? $one : User::factory()->create();
        if (! $sameUser) {
            $two->assignRole($role);
        }
        $subjectOne = 'first';
        $subjectTwo = $sameUser ? 'second' : 'first';
        if ($operation === 'unlink') {
            $identity = UserSsoIdentity::create(['user_id' => $one->id, 'issuer' => 'https://keycloak.example.test/realms/aaud', 'subject' => 'first']);
            $subjectOne = $subjectTwo = (string) $identity->id;
        }
        $dir = sys_get_temp_dir().'/pazsalvo-sso-race-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $processes = [];
        $start = function ($user, $subject, $name, $release) use ($actor, $operation, $dir, &$processes) {
            $process = proc_open([PHP_BINARY, base_path('tests/helpers/sso-identity-worker.php'), $operation,
                (string) $actor->id, (string) $user->id, $subject, $dir.'/'.$name, $release],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        };
        $await = function (callable $condition) {
            $deadline = microtime(true) + 12;
            while (microtime(true) < $deadline) {
                if ($condition()) {
                    return true;
                } usleep(10000);
            }

            return false;
        };
        $outputs = [];
        try {
            $start($one, $subjectOne, 'one', $dir.'/release');
            $this->assertTrue($await(fn () => is_file($dir.'/one')), 'First transaction must hold lock');
            $start($two, $subjectTwo, 'two', '-');
            $this->assertTrue($await(fn () => is_file($dir.'/two.started')));
            $pid = (int) file_get_contents($dir.'/two.started');
            $this->assertTrue($await(fn () => (DB::selectOne('select wait_event from pg_stat_activity where pid = ?', [$pid])->wait_event ?? '') === $waitEvent), 'Independent second connection must actually block');
        } finally {
            touch($dir.'/release');
            foreach ($processes as [$process, $pipes]) {
                fclose($pipes[0]);
                $outputs[] = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            } rmdir($dir);
        }
        $this->assertSame(['committed', 'rejected'], $outputs);
        $this->assertDatabaseCount('user_sso_identities', in_array($operation, ['unlink', 'username']) ? 0 : 1);
        if ($operation === 'username') {
            $this->assertSame(1, User::where('username', 'first')->count());
        }
        $this->assertDatabaseCount('aaud_auth_catalog_operations', 0);
        if ($operation === 'create') {
            $this->assertSame(1, User::where('email', 'like', 'sso-created-%')->count());
        }
    }
}
