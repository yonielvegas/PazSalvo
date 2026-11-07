<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AdministrativeTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_ends_with((string) config('database.connections.pgsql.database'), '_testing')) {
    throw new RuntimeException('Testing database required');
}
if (DB::selectOne('select current_database() as name')->name !== config('database.connections.pgsql.database')) {
    throw new RuntimeException('Actual testing database mismatch');
}
[$script, $operation, $id, $roleId, $ready, $release] = $argv;
file_put_contents($ready.'.started', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
try {
    if ($operation === 'raw_attach') {
        DB::transaction(function () use ($id, $roleId, $ready, $release) {
            DB::table('model_has_roles')->insert(['model_type' => User::class, 'model_id' => $id, 'role_id' => $roleId]);
            file_put_contents($ready, 'inserted');
            $deadline = microtime(true) + 10;
            while ($release !== '-' && ! is_file($release)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Barrier timeout');
                }
                usleep(10000);
            }
        });
        echo 'committed';
        exit;
    }
    AdministrativeTransaction::run(function () use ($operation, $id, $roleId, $ready, $release) {
        file_put_contents($ready, 'locked');
        $deadline = microtime(true) + 10;
        while ($release !== '-' && ! is_file($release)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Barrier timeout');
            }
            usleep(10000);
        }
        if ($operation === 'deactivate') {
            User::findOrFail($id)->update(['is_active' => false]);
        } elseif ($operation === 'delete') {
            User::findOrFail($id)->delete();
        } elseif ($operation === 'assign') {
            User::findOrFail($id)->syncRoles([Role::findOrFail($roleId)]);
        } elseif ($operation === 'disable') {
            $role = Role::findOrFail($id);
            if (DB::table('model_has_roles')->where('role_id', $id)->exists()) {
                throw ValidationException::withMessages(['role' => 'Assigned role']);
            }
            $role->update(['is_active' => false]);
        }
    });
    echo 'committed';
} catch (ValidationException) {
    echo 'rejected';
} catch (QueryException $exception) {
    if ($exception->getCode() !== '23505') {
        throw $exception;
    }
    echo 'rejected';
}
