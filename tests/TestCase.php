<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        if (! $app->environment('testing') || $connection !== 'pgsql' || ! is_string($database) || ! str_ends_with($database, '_testing')) {
            throw new \RuntimeException('Tests require an explicitly isolated PostgreSQL database ending in _testing.');
        }
        // Resolve DB_URL as well: a configured test name must never hide a different database.
        $actual = $app['db']->connection()->selectOne('select current_database() as name')->name;
        if ($actual !== $database) {
            throw new \RuntimeException('The actual PostgreSQL database does not match the isolated testing database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
