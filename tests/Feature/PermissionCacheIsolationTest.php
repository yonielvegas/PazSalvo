<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_permissions_cannot_use_the_operational_file_cache(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('permission.cache.store'));
        $this->assertInstanceOf(ArrayStore::class, app(PermissionRegistrar::class)->getCacheStore());
    }

    public function test_rebuilding_stale_permission_cache_restores_existing_authorization_without_role_changes(): void
    {
        $this->seed(MasterDataSeeder::class);
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('operador');
        $registrar = app(PermissionRegistrar::class);
        $this->assertTrue($user->fresh()->can('consultar paz y salvo'));
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $repository = $registrar->getCacheRepository();
        $stale = $repository->get($registrar->cacheKey);
        $this->assertIsArray($stale);
        $stale['roles'] = []; // Same observed defect: persistent cache has no role associations.
        $repository->put($registrar->cacheKey, $stale, 60);
        $registrar->clearPermissionsCollection();
        $this->assertFalse($user->fresh()->can('consultar paz y salvo'));
        $this->artisan('permission:cache-reset')->assertSuccessful();
        $this->assertTrue($user->fresh()->can('consultar paz y salvo'));
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertSame(['operador'], $user->fresh()->getRoleNames()->all());
    }
}
