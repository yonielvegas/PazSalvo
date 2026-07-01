<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConsultPazSalvoTest extends TestCase
{
    use RefreshDatabase;

    public function test_consulting_does_not_persist_official_or_query_records(): void
    {
        $user = User::factory()->create();
        $permission = Permission::create(['name' => 'consultar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response(['account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE'], 'balances' => ['total_balance' => 0], 'debts' => []]),
        ]);

        $this->actingAs($user)->post(route('paz-salvo.consult'), ['client_number' => '34787'])
            ->assertRedirect()->assertSessionHas('paz_salvo_query')->assertSessionHas('result.status', 'debt_free');
        $this->assertDatabaseCount('paz_salvos', 0);
        $this->assertDatabaseCount('debt_queries', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_internal_panel_requires_authentication(): void
    {
        $this->get(route('paz-salvo.index'))->assertRedirect(route('login'));
    }
}
