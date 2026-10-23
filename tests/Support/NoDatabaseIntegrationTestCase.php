<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Pure boundary tests. Any accidental database access fails instead of connecting. */
abstract class NoDatabaseIntegrationTestCase extends TestCase
{
    protected Container $app;

    protected function setUp(): void
    {
        parent::setUp();
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        Model::unsetConnectionResolver();
        $this->app = new Container;
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository(['app' => ['env' => 'testing'], 'auth' => ['defaults' => ['guard' => 'web'], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']], 'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]]]]));
        $this->app['config']->set('permission', require dirname(__DIR__, 2).'/config/permission.php');
        $this->app->instance('date', new DateFactory);
        $this->app->instance(Factory::class, new Factory);
        $database = Mockery::mock();
        $database->shouldReceive('connection', 'table', 'transaction', 'select', 'statement')->never();
        DB::swap($database);
        Log::swap(Mockery::spy(LoggerInterface::class));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Model::clearBootedModels();
            Model::unsetEventDispatcher();
            Model::unsetConnectionResolver();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance(null);
            parent::tearDown();
        }
    }
}
