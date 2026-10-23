<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\InvalidRoleCatalogException;
use App\Integrations\AaudAuth\Keycloak\TechnicalTokenProvider;
use App\Integrations\AaudAuth\Roles\RoleCatalogBuilder;
use App\Models\Role;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoleCatalogBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Role catalog must never call HTTP'));
        $this->app->bind(TechnicalTokenProvider::class, fn () => throw new \LogicException('Catalog must not resolve the token provider'));
    }

    public function test_builds_all_five_real_roles_with_exact_payload_fields(): void
    {
        $this->seed(MasterDataSeeder::class);
        $expected = [];
        $codes = Role::RESERVED_NAMES;
        sort($codes, SORT_STRING);
        foreach ($codes as $code) {
            $expected[] = ['code' => $code, 'name' => $code, 'active' => true];
        }
        $this->assertSame($expected, app(RoleCatalogBuilder::class)->build());
        Http::assertNothingSent();
    }

    public function test_includes_custom_inactive_roles_excludes_other_guards_and_keeps_stable_code(): void
    {
        $role = Role::create(['name' => 'Revisión Técnica', 'guard_name' => 'web', 'is_active' => false]);
        Role::create(['name' => 'API role', 'code' => $role->code, 'guard_name' => 'api']);
        Role::create(['name' => 'custom', 'code' => 'a', 'guard_name' => 'web']);
        $role->update(['name' => 'Nombre nuevo']);
        $this->assertSame([
            ['code' => 'a', 'name' => 'custom', 'active' => true],
            ['code' => 'revision_tecnica', 'name' => 'Nombre nuevo', 'active' => false],
        ], app(RoleCatalogBuilder::class)->build());
        $this->assertSame('revision_tecnica', $role->fresh()->code);
    }

    public function test_builder_uses_one_select_without_relations_or_writes(): void
    {
        $this->seed(MasterDataSeeder::class);
        $before = DB::table('roles')->orderBy('id')->get()->toJson();
        $auditCount = DB::table('audit_logs')->count();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });
        app(RoleCatalogBuilder::class)->build();
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('select ', strtolower($queries[0]));
        $this->assertStringNotContainsString('permissions', $queries[0]);
        $this->assertStringNotContainsString('users', $queries[0]);
        $this->assertStringNotContainsString('"id"', $queries[0]);
        $this->assertSame($before, DB::table('roles')->orderBy('id')->get()->toJson());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public function test_preview_prints_only_catalog_without_tokens_versions_or_writes(): void
    {
        $this->seed(MasterDataSeeder::class);
        config(['aaud-auth.enabled' => true]);
        $before = DB::table('roles')->orderBy('id')->get()->toJson();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('sso:roles:preview'));
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(5, $output);
        foreach ($output as $item) {
            $this->assertSame(['code', 'name', 'active'], array_keys($item));
        }
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('select ', strtolower($queries[0]));
        $this->assertSame($before, DB::table('roles')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_empty_preview_fails_instead_of_printing_a_replacement_catalog(): void
    {
        Role::create(['name' => 'API only', 'guard_name' => 'api']);
        $this->artisan('sso:roles:preview')->expectsOutputToContain('El catálogo web está vacío')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_invalid_database_name_fails_without_repairing_or_exposing_data(): void
    {
        $role = Role::create(['name' => 'custom', 'guard_name' => 'web']);
        DB::table('roles')->where('id', $role->id)->update(['name' => "  \t "]);
        $this->artisan('sso:roles:preview')->expectsOutputToContain('El catálogo contiene un nombre vacío o inválido.')->assertFailed();
        $this->assertSame("  \t ", $role->fresh()->name);
        $this->assertDatabaseCount('roles', 1);
        Http::assertNothingSent();
    }

    public function test_builder_rejects_an_empty_database(): void
    {
        $this->expectException(InvalidRoleCatalogException::class);
        app(RoleCatalogBuilder::class)->build();
    }
}
