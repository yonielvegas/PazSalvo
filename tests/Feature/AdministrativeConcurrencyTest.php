<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdministrativeConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();
        parent::tearDown();
    }

    private function race(array $first, array $second, string $expectedWait = 'advisory'): array
    {
        $dir = sys_get_temp_dir().'/pazsalvo-race-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $start = function ($args, $name, $release) use ($dir) {
            $process = proc_open([PHP_BINARY, base_path('tests/helpers/administration-worker.php'), ...array_map('strval', $args), $dir.'/'.$name, $release], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);

            return [$process, $pipes];
        };
        [$one, $pipesOne] = $start($first, 'one', $dir.'/release');
        $deadline = microtime(true) + 10;
        while (! is_file($dir.'/one') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($dir.'/one');
        [$two, $pipesTwo] = $start($second, 'two', '-');
        $deadline = microtime(true) + 10;
        while (! is_file($dir.'/two.started') && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($dir.'/two.started');
        $pid = (int) file_get_contents($dir.'/two.started');
        $blocked = false;
        while (microtime(true) < $deadline) {
            $wait = DB::selectOne('select wait_event from pg_stat_activity where pid = ?', [$pid]);
            if (($wait->wait_event ?? '') === $expectedWait) {
                $blocked = true;
                break;
            }
            usleep(10000);
        }
        touch($dir.'/release');
        $outputs = [];
        foreach ([[$one, $pipesOne], [$two, $pipesTwo]] as [$process, $pipes]) {
            fclose($pipes[0]);
            $outputs[] = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
        $this->assertTrue($blocked, 'The second independent connection must wait for the first transaction');
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);

        return $outputs;
    }

    public function test_concurrent_deactivations_preserve_one_active_admin(): void
    {
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $a = User::factory()->create();
        $a->assignRole($role);
        $b = User::factory()->create();
        $b->assignRole($role);
        $this->assertSame(['committed', 'rejected'], $this->race(['deactivate', $a->id, 0], ['deactivate', $b->id, 0]));
        $this->assertSame(1, User::role('admin')->where('is_active', true)->count());
    }

    public function test_concurrent_replacements_leave_exactly_one_role(): void
    {
        $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $this->assertSame(['committed', 'committed'], $this->race(['assign', $user->id, $a->id], ['assign', $user->id, $b->id]));
        $this->assertSame([$b->id], $user->fresh()->roles->modelKeys());
    }

    public function test_deactivation_and_concurrent_demotion_preserve_last_admin(): void
    {
        $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $other = Role::create(['name' => 'consulta', 'guard_name' => 'web']);
        $a = User::factory()->create();
        $a->assignRole($admin);
        $b = User::factory()->create();
        $b->assignRole($admin);
        $this->assertSame(['committed', 'rejected'], $this->race(['deactivate', $a->id, 0], ['assign', $b->id, $other->id]));
        $this->assertSame(1, User::role('admin')->where('is_active', true)->count());
    }

    public function test_deactivation_and_concurrent_delete_preserve_last_admin(): void
    {
        $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $a = User::factory()->create();
        $a->assignRole($admin);
        $b = User::factory()->create();
        $b->assignRole($admin);
        $this->assertSame(['committed', 'rejected'], $this->race(['deactivate', $a->id, 0], ['delete', $b->id, 0]));
        $this->assertSame(1, User::role('admin')->where('is_active', true)->count());
    }

    public function test_role_assignment_and_deactivation_are_serialized(): void
    {
        $role = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $this->assertSame(['committed', 'rejected'], $this->race(['disable', $role->id, 0], ['assign', $user->id, $role->id]));
        $this->assertSame(0, $user->roles()->count());
        $this->assertFalse((bool) $role->fresh()->is_active);
    }

    public function test_concurrent_raw_inserts_are_rejected_by_the_partial_unique_index(): void
    {
        $a = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Role::create(['name' => 'b', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $this->assertSame(['committed', 'rejected'], $this->race(['raw_attach', $user->id, $a->id], ['raw_attach', $user->id, $b->id], 'transactionid'));
        $this->assertSame([$a->id], $user->fresh()->roles->modelKeys());
    }

    public function test_assigning_first_prevents_a_concurrent_role_deactivation(): void
    {
        $role = Role::create(['name' => 'a', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $this->assertSame(['committed', 'rejected'], $this->race(['assign', $user->id, $role->id], ['disable', $role->id, 0]));
        $this->assertTrue((bool) $role->fresh()->is_active);
        $this->assertSame([$role->id], $user->fresh()->roles->modelKeys());
    }
}
