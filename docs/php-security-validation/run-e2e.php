<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$name = 'paz_php_ci_e2e_20261029_testing';
$db = $app['db']->connection();
if (! $db->selectOne('select 1 as present from pg_database where datname=?', [$name])) {
    $db->statement('CREATE DATABASE '.$name);
}
$c = config('database.connections.pgsql');
$root = dirname(__DIR__, 2);
$store = __DIR__.'/e2e-storage';
foreach (['app/private/templates', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs'] as $d) {
    @mkdir($store.'/'.$d, 0700, true);
}
$templates = $root.'/storage/app/private/templates';
if (is_dir($templates)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templates, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $to = $store.'/app/private/templates/'.substr($file->getPathname(), strlen($templates) + 1);
        if ($file->isDir()) {
            @mkdir($to, 0700, true);
        } else {
            copy($file->getPathname(), $to);
        }
    }
}
$env = array_merge(getenv(), Dotenv\Dotenv::parse(file_get_contents($root.'/.env.testing')), [
    'APP_ENV' => 'testing', 'APP_URL' => 'http://127.0.0.1:18012', 'APP_DEBUG' => 'false', 'BCRYPT_ROUNDS' => '4',
    'DB_URL' => '', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $name, 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'],
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'LARAVEL_STORAGE_PATH' => $store,
    'AAUD_OAUTH_ENABLED' => 'false', 'AAUD_OAUTH_AUTOMATIC' => 'false', 'AAUD_OIDC_ENABLED' => 'false',
    'INTERNAL_NETWORK_ENABLED' => 'false', 'APP_ALLOWED_HOSTS' => '127.0.0.1', 'PUBLIC_VERIFICATION_BASE_URL' => 'http://127.0.0.1:18012/verificar',
    'E2E_PASSWORD' => 'e2e-test-password', 'USER_TEMPORARY_PASSWORD' => 'e2e-test-password', 'SEED_TEMPORARY_USER_PASSWORD' => 'e2e-test-password',
    'APP_CONFIG_CACHE' => __DIR__.'/e2e-config.php', 'APP_SERVICES_CACHE' => __DIR__.'/e2e-services.php', 'APP_PACKAGES_CACHE' => __DIR__.'/e2e-packages.php', 'APP_ROUTES_CACHE' => __DIR__.'/e2e-routes.php',
    'VIEW_COMPILED_PATH' => $store.'/framework/views', 'TMPDIR' => $root.'/.qa-php-tmp', 'CI' => 'true']);
$run = function (array $cmd) use ($env, $root) {
    $p = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['redirect', 1]], $pipes, $root, $env);
    fclose($pipes[0]);
    fwrite(STDOUT, stream_get_contents($pipes[1]));
    fclose($pipes[1]);

    return proc_close($p);
};
foreach ([[PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], [PHP_BINARY, 'artisan', 'db:seed', '--class=ProductionBootstrapSeeder', '--force'], [PHP_BINARY, 'artisan', 'db:seed', '--class=ProductionBootstrapSeeder', '--force'], [PHP_BINARY, 'artisan', 'db:seed', '--force']] as $cmd) {
    if ($run($cmd) !== 0) {
        exit(1);
    }
}
exit($run(['npx', 'playwright', 'test', '--config', 'docs/php-security-validation/e2e.config.ts']));
