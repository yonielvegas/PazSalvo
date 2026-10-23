<?php

use App\Models\User;
use App\Services\UserSsoIdentityManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'pgsql'
    || ! str_ends_with((string) config('database.connections.pgsql.database'), '_testing')
    || DB::selectOne('select current_database() as name')->name !== config('database.connections.pgsql.database')) {
    throw new RuntimeException('Isolated PostgreSQL testing database required');
}
[$script, $operation, $actorId, $userId, $subject, $ready, $release] = $argv;
$issuer = 'https://keycloak.example.test/realms/aaud';
config(['aaud-auth' => ['enabled' => true, 'api_url' => 'https://auth.example.test', 'issuer' => $issuer,
    'token_url' => $issuer.'/protocol/openid-connect/token', 'client_id' => 'pazysalvo-sync', 'client_secret' => 'fixture-secret',
    'connect_timeout' => 5, 'timeout' => 10, 'verify_tls' => true, 'ca_bundle' => null, 'token_expiry_leeway' => 30]]);
Http::preventStrayRequests();
Http::fake(function ($request) use ($issuer, $subject) {
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('HTTP must precede the database transaction');
    }

    return str_ends_with($request->url(), '/token')
        ? Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300])
        : Http::response(['data' => [['issuer' => $issuer, 'subject' => $subject, 'username' => 'directory-user',
            'name' => 'Directory User', 'name_is_fallback' => false, 'email' => null, 'enabled' => true]], 'page' => 1, 'limit' => 20, 'has_more' => false]);
});
$barrier = function () use ($ready, $release) {
    file_put_contents($ready, 'locked');
    $deadline = microtime(true) + 15;
    while ($release !== '-' && ! is_file($release)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out');
        }
        usleep(10000);
    }
};
file_put_contents($ready.'.started', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
$barrierUsed = false;
DB::listen(function ($query) use ($operation, $barrier, &$barrierUsed) {
    if ($operation !== 'raw' && ! $barrierUsed && str_contains($query->sql, 'pg_advisory_xact_lock')) {
        $barrierUsed = true;
        $barrier();
    }
});
try {
    if (in_array($operation, ['raw', 'username'])) {
        DB::transaction(function () use ($operation, $userId, $issuer, $subject, $barrier) {
            if ($operation === 'username') {
                DB::table('users')->where('id', $userId)->update(['username' => $subject]);
            } else {
                DB::table('user_sso_identities')->insert(['user_id' => $userId, 'issuer' => $issuer, 'subject' => $subject]);
            }
            $barrier();
        });
    } else {
        $actor = User::findOrFail($actorId);
        $target = User::findOrFail($userId);
        $request = Request::create('/admin/users/'.$userId.'/sso-identity', 'POST');
        $request->setUserResolver(fn () => $actor);
        $manager = app(UserSsoIdentityManager::class);
        if ($operation === 'unlink') {
            $manager->unlink($target, $actor, (int) $subject, $request);
        } elseif ($operation === 'create') {
            $manager->createAndLink($actor, ['issuer' => $issuer, 'subject' => $subject, 'query' => 'directory', 'page' => 1], $request, function () use ($actor, $userId) {
                $user = User::factory()->create(['email' => 'sso-created-'.$userId.'@example.test', 'agency_id' => $actor->agency_id]);
                $user->syncRoles(['operador']);

                return $user;
            });
        } else {
            $manager->link($target, $actor, ['issuer' => $issuer, 'subject' => $subject, 'query' => 'directory', 'page' => 1], $request);
        }
    }
    echo 'committed';
} catch (ValidationException) {
    echo 'rejected';
} catch (QueryException $e) {
    if ($e->getCode() !== '23505') {
        throw $e;
    } echo 'rejected';
}
