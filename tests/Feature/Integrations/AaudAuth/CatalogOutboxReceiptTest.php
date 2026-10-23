<?php

namespace Tests\Feature\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Integrations\AaudAuth\Roles\CatalogOutbox;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogOutboxReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_validated_snapshot_is_bound_to_the_persisted_operation_using_only_simulated_http(): void
    {
        Http::preventStrayRequests();
        $this->seed(MasterDataSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        DB::table('aaud_auth_catalog_states')->insertOrIgnore([
            'application_code' => 'pazysalvo', 'resource' => 'roles', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $outbox = app(CatalogOutbox::class);
        $outbox->recordRemoteObservation(80, 'FIXTURE-REMOTE-V80', $admin);
        $operation = $outbox->prepare();
        $claim = $outbox->claim($operation->id);
        $echoed = array_map(fn ($role) => [...$role, 'description' => null], $operation->payload()['roles']);
        config(['aaud-auth.enabled' => true, 'aaud-auth.api_url' => 'https://auth.example.test',
            'aaud-auth.token_url' => 'https://keycloak.example.test/token', 'aaud-auth.client_id' => 'pazysalvo-sync',
            'aaud-auth.client_secret' => 'fake-secret', 'aaud-auth.verify_tls' => true, 'aaud-auth.ca_bundle' => null]);
        Http::fake([
            'https://keycloak.example.test/token' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer', 'expires_in' => 300]),
            'https://auth.example.test/api/integrations/v1/applications/pazysalvo/roles' => Http::response([
                'status' => 'applied', 'current_version' => 81, 'historical' => false,
                'data' => [...$operation->payload(), 'application_code' => 'pazysalvo', 'roles' => $echoed],
            ]),
        ]);
        $receipt = app(AaudAuthClient::class)->syncRoleCatalog(...array_values($operation->payload()));
        $this->assertSame($echoed, $receipt->roles);
        $confirmed = $outbox->confirm($operation->id, $claim->claimId, $receipt);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertTrue($outbox->isCurrentlySynchronized());
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->data() === $operation->payload());
        Http::assertSentCount(2);
    }
}
