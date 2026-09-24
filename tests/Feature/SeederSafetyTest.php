<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Database\Seeders\DevelopmentBootstrapSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_data_seeder_is_idempotent_and_creates_no_users_or_agencies(): void
    {
        $seeder = app(MasterDataSeeder::class);

        $seeder->run();
        $roleCount = Role::count();
        $permissionCount = Permission::count();
        $seeder->run();

        $this->assertSame(5, $roleCount);
        $this->assertSame(8, $permissionCount);
        $this->assertSame($roleCount, Role::count());
        $this->assertSame($permissionCount, Permission::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, Agency::count());
    }

    public function test_development_bootstrap_seeder_is_prohibited_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            app(DevelopmentBootstrapSeeder::class)->run();
            $blocked = false;
        } catch (\RuntimeException $exception) {
            $blocked = true;
            $this->assertSame('DevelopmentBootstrapSeeder is prohibited in production.', $exception->getMessage());
        }

        $this->assertTrue($blocked);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Agency::count());
    }
}
