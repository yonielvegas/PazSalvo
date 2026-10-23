<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Integrations\AaudAuth\Roles\RoleCatalogBuilder;
use App\Models\Role;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoleCatalogTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_transmits_the_complete_builder_snapshot_without_database_writes(): void
    {
        Http::preventStrayRequests();
        $this->seed(MasterDataSeeder::class);
        Role::create(['code' => 'comercial', 'name' => 'Comercial', 'guard_name' => 'web', 'is_active' => true]);
        Role::create(['code' => 'archivo', 'name' => 'Archivo', 'guard_name' => 'web', 'is_active' => false]);
        Role::create(['code' => 'external', 'name' => 'Other guard', 'guard_name' => 'api']);
        $catalog = app(RoleCatalogBuilder::class)->build();
        $this->assertCount(7, $catalog);
        $operation = ['version' => 12, 'operation_id' => 'a0000000-0000-4000-8000-000000000012', 'roles' => $catalog];
        config(['aaud-auth.enabled' => true, 'aaud-auth.api_url' => 'https://auth.example.test',
            'aaud-auth.token_url' => 'https://keycloak.example.test/token', 'aaud-auth.client_id' => 'pazysalvo-sync',
            'aaud-auth.client_secret' => 'fixture-secret', 'aaud-auth.verify_tls' => true, 'aaud-auth.ca_bundle' => null]);
        Http::fake([
            'https://keycloak.example.test/token' => Http::response(['access_token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 300]),
            'https://auth.example.test/api/integrations/v1/applications/pazysalvo/roles' => Http::response([
                'status' => 'applied', 'current_version' => 12, 'historical' => false,
                'data' => [...$operation, 'application_code' => 'pazysalvo',
                    'roles' => array_map(fn ($role) => [...$role, 'description' => null], $catalog)],
            ]),
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $result = app(AaudAuthClient::class)->syncRoleCatalog(...array_values($operation));
        $this->assertTrue($result->confirmsCurrentCatalog());
        $this->assertSame([], $queries);
        Http::assertSent(function ($request) use ($operation) {
            if ($request->method() !== 'PUT') {
                return false;
            }
            $this->assertSame($operation, $request->data());
            $this->assertSame(['code' => 'archivo', 'name' => 'Archivo', 'active' => false], $request['roles'][2]);
            foreach ($request['roles'] as $role) {
                $this->assertSame(['code', 'name', 'active'], array_keys($role));
                $this->assertNotSame('external', $role['code']);
            }

            return true;
        });
        Http::assertSentCount(2);
    }
}
