<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Browser fixtures are served only by this explicitly selected test router.
$public = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($public.'/'.rawurldecode($path ?: '/'));
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = $app['db']->connection()->selectOne('select current_database() as name')->name;
if (! $app->environment('testing') || ! str_ends_with($database, '_testing') || getenv('E2E_SSO_DIRECTORY_FIXTURE') !== '1') {
    throw new RuntimeException('The browser fixture requires an isolated testing database and explicit opt-in.');
}

config([
    'aaud-oauth.enabled' => true, 'aaud-oauth.automatic' => false,
    'aaud-oauth.api_url' => 'https://sso-fixture.example.test',
    'aaud-oauth.issuer' => 'https://sso-fixture.example.test/realms/aaud',
    'aaud-oauth.token_url' => 'https://sso-fixture.example.test/realms/aaud/protocol/openid-connect/token',
    'aaud-oauth.client_id' => 'pazysalvo-sync', 'aaud-oauth.client_secret' => 'synthetic-browser-fixture',
    'permission.cache.store' => 'array', 'cache.default' => 'array',
]);
$run = substr(hash('sha256', getenv('E2E_SSO_RUN_ID') ?: $database), 0, 12);
$identity = [
    'issuer' => config('aaud-oauth.issuer'), 'subject' => 'e2e-single-role-'.$run,
    'username' => 'single-role-'.$run, 'name' => 'Single Role E2E',
    'email' => 'single-role-'.$run.'@example.test', 'enabled' => true, 'name_is_fallback' => false,
];
Http::preventStrayRequests();
Http::fake([
    config('aaud-oauth.token_url') => Http::response([
        'access_token' => 'synthetic-directory-token', 'token_type' => 'Bearer', 'expires_in' => 300, 'scope' => 'directory.read',
    ]),
    'https://sso-fixture.example.test/api/integrations/v1/directory/users*' => fn ($request) => Http::response([
        'data' => [$identity], 'page' => (int) $request['page'], 'limit' => 20, 'has_more' => false,
    ]),
]);

$app->handleRequest(Request::capture());
