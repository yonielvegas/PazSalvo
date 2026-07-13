<?php

namespace Tests\Feature;

use App\Models\PazSalvo;
use App\Models\User;
use App\Services\PazSalvoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ConsultPazSalvoTest extends TestCase
{
    use RefreshDatabase;

    private function authedUser(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['name' => 'consultar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function fakeWidergy(array $debts, array $balances = [], ?string $city = 'BELISARIO FRIAS'): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response([
                'account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE TEST', 'city' => $city],
                'balances' => array_merge(['total_balance' => 0], $balances),
                'debts' => $debts,
            ]),
        ]);
    }

    private function assertResult(\Illuminate\Testing\TestResponse $response, array $expected): void
    {
        $response->assertSessionHas('result.status', $expected['status']);
        $response->assertSessionHas('result.can_generate_paz_salvo', $expected['can_generate_paz_salvo']);
        $response->assertSessionHas('result.requires_energy_warning', $expected['requires_energy_warning']);
        $response->assertSessionHas('result.balances.aseo_balance', $expected['aseo_balance']);
        $response->assertSessionHas('result.balances.energy_balance', $expected['energy_balance']);
    }

    public function test_consulting_does_not_persist_official_or_query_records(): void
    {
        $this->fakeWidergy([]);
        $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787'])
            ->assertRedirect()->assertSessionHas('paz_salvo_query');
        $this->assertDatabaseCount('paz_salvos', 0);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_internal_panel_requires_authentication(): void
    {
        $this->get(route('paz-salvo.index'))->assertRedirect(route('login'));
    }

    public function test_client_number_must_be_numeric_and_at_most_twelve_digits(): void
    {
        $this->actingAs($this->authedUser())
            ->post(route('paz-salvo.consult'), ['client_number' => '1234567890123'])
            ->assertSessionHasErrors(['client_number']);

        $this->actingAs($this->authedUser())
            ->post(route('paz-salvo.consult'), ['client_number' => '123ABC'])
            ->assertSessionHasErrors(['client_number']);
    }

    public function test_generation_validation_error_keeps_consulted_client_result_available(): void
    {
        $user = $this->authedUser();
        $permission = Permission::firstOrCreate(['name' => 'generar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $query = [
            'query_token' => '00000000-0000-4000-8000-000000000001',
            'status' => 'debt_free',
            'client_number' => '34787',
            'holder_name' => 'CLIENTE TEST',
            'address' => 'CALLE 1',
            'city' => 'BELISARIO FRIAS',
            'rate' => 'Residencial',
            'balances' => ['aseo_balance' => 0, 'energy_balance' => 0, 'total_balance' => 0],
            'debts' => [],
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => false,
        ];

        $this->actingAs($user)
            ->withSession([
                'paz_salvo_query' => [
                    'token' => $query['query_token'],
                    'client_number' => '34787',
                    'expires_at' => now()->addMinutes(15)->timestamp,
                ],
                'paz_salvo_result' => $query,
            ])
            ->post(route('paz-salvo.generate'), [
                'query_token' => $query['query_token'],
                'numero_factura' => '123',
            ])
            ->assertSessionHasErrors(['numero_factura'])
            ->assertSessionHas('result.client_number', '34787');
    }

    public function test_consult_page_does_not_revive_old_session_result_without_flash(): void
    {
        $user = $this->authedUser();
        $query = [
            'query_token' => '00000000-0000-4000-8000-000000000001',
            'status' => 'not_san_miguelito',
            'client_number' => '34787',
            'holder_name' => 'CLIENTE TEST',
            'address' => 'CALLE 1',
            'city' => 'PANAMA',
            'rate' => 'Residencial',
            'balances' => ['aseo_balance' => 0, 'energy_balance' => 0, 'total_balance' => 0],
            'debts' => [],
            'can_generate_paz_salvo' => false,
            'requires_energy_warning' => false,
        ];

        $this->actingAs($user)
            ->withSession(['paz_salvo_result' => $query])
            ->get(route('paz-salvo.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('flash.result', null));
    }

    public function test_successful_generation_clears_consult_session_state(): void
    {
        $user = $this->authedUser();
        $permission = Permission::firstOrCreate(['name' => 'generar paz y salvo', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        $query = [
            'query_token' => '00000000-0000-4000-8000-000000000001',
            'status' => 'debt_free',
            'client_number' => '34787',
            'holder_name' => 'CLIENTE TEST',
            'address' => 'CALLE 1',
            'city' => 'BELISARIO FRIAS',
            'rate' => 'Residencial',
            'balances' => ['aseo_balance' => 0, 'energy_balance' => 0, 'total_balance' => 0],
            'debts' => [],
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => false,
        ];
        $document = new PazSalvo(['id' => 123]);
        $document->exists = true;

        $this->mock(PazSalvoService::class, function ($mock) use ($document, $user) {
            $mock->shouldReceive('generate')
                ->once()
                ->with('34787', Mockery::on(fn (User $actual) => $actual->is($user)), '123456')
                ->andReturn($document);
        });

        $this->actingAs($user)
            ->withSession([
                'paz_salvo_query' => [
                    'token' => $query['query_token'],
                    'client_number' => '34787',
                    'expires_at' => now()->addMinutes(15)->timestamp,
                ],
                'paz_salvo_result' => $query,
            ])
            ->post(route('paz-salvo.generate'), [
                'query_token' => $query['query_token'],
                'numero_factura' => '123456',
            ])
            ->assertRedirect(route('paz-salvos.show', $document))
            ->assertSessionMissing('paz_salvo_query')
            ->assertSessionMissing('paz_salvo_result');
    }

    public function test_aseo_zero_energy_zero_allows_generation_without_warning(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Al día'],
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'debt_free',
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => false,
            'aseo_balance' => 0.0,
            'energy_balance' => 0.0,
        ]);
    }

    public function test_aseo_zero_energy_nonzero_allows_generation_with_warning(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 50.75, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Pendiente'],
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'debt_free_aseo_with_energy_debt',
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => true,
            'aseo_balance' => 0.0,
            'energy_balance' => 50.75,
        ]);
    }

    public function test_aseo_nonzero_blocks_generation(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 30.0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Pendiente'],
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Al día'],
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'has_aseo_debt',
            'can_generate_paz_salvo' => false,
            'requires_energy_warning' => false,
            'aseo_balance' => 30.0,
            'energy_balance' => 0.0,
        ]);
    }

    public function test_total_a_pagar_does_not_affect_generation_decision(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 100.0, 'document_type' => 'TOTAL A PAGAR', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 100.0, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Pendiente'],
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'debt_free_aseo_with_energy_debt',
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => true,
            'aseo_balance' => 0.0,
            'energy_balance' => 100.0,
        ]);
    }

    public function test_energy_without_accent_is_recognized(): void
    {
        $this->fakeWidergy([
            ['period' => '202605', 'amount' => 25.0, 'document_type' => 'Saldo a 30 días Energia(MAY/2026)', 'status' => 'Pendiente'],
            ['period' => '202605', 'amount' => 0, 'document_type' => 'Saldo a 30 días Aseo(MAY/2026)', 'status' => 'Al día'],
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'debt_free_aseo_with_energy_debt',
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => true,
            'aseo_balance' => 0.0,
            'energy_balance' => 25.0,
        ]);
    }

    public function test_not_found_when_no_account_data(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response(['account' => [], 'balances' => ['total_balance' => 0], 'debts' => []]),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '99999']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_found');
    }

    public function test_widergy_500_on_job_request_shows_friendly_error(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(null, 500),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['client_number']);
    }

    public function test_widergy_connection_timeout_shows_friendly_error(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out');
            },
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['client_number']);
    }

    public function test_widergy_500_on_job_poll_shows_friendly_error(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response(null, 503),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['client_number']);
    }

    public function test_widergy_job_never_completes_shows_friendly_error(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response(['status' => 'running']),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['client_number']);
    }

    public function test_widergy_missing_job_id_shows_friendly_error(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['foo' => 'bar']),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionHasErrors(['client_number']);
    }

    public function test_belisario_frias_passes_san_miguelito_validation(): void
    {
        $this->fakeWidergy([], [], 'BELISARIO FRIAS');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '610479']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'debt_free');
        $response->assertSessionMissing('result.san_miguelito_validation');
    }

    public function test_amelia_denis_de_icaza_passes_san_miguelito_validation(): void
    {
        $this->fakeWidergy([], [], 'AMELIA DENIS DE ICAZA');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'debt_free');
        $response->assertSessionMissing('result.san_miguelito_validation');
    }

    public function test_accented_jose_domingo_espinar_passes_san_miguelito_validation(): void
    {
        $this->fakeWidergy([], [], 'JOSÉ DOMINGO ESPINAR');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'debt_free');
        $response->assertSessionMissing('result.san_miguelito_validation');
    }

    public function test_city_outside_san_miguelito_is_blocked(): void
    {
        $this->fakeWidergy([], [], 'PANAMA');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
        $response->assertSessionHas('result.san_miguelito_validation.is_san_miguelito', false);
        $response->assertSessionHas('result.san_miguelito_validation.received_city', 'PANAMA');
    }

    public function test_null_city_is_blocked(): void
    {
        $this->fakeWidergy([], [], null);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
        $response->assertSessionHas('result.san_miguelito_validation.is_san_miguelito', false);
        $this->assertNull(session('result.san_miguelito_validation.received_city'));
    }

    public function test_empty_city_is_blocked(): void
    {
        $this->fakeWidergy([], [], '');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
    }

    public function test_not_san_miguelito_with_aseo_zero_energy_zero_is_not_debt_free(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Al día'],
        ], [], 'PANAMA');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
        $response->assertSessionHas('result.san_miguelito_validation.message');
    }

    public function test_not_san_miguelito_with_aseo_zero_energy_nonzero_shows_no_popup(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 20, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Pendiente'],
        ], [], 'PANAMA');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
    }

    public function test_not_san_miguelito_with_aseo_nonzero_shows_no_debt_modal(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 10, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Pendiente'],
        ], [], 'PANAMA');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
    }

    public function test_san_miguelito_with_aseo_zero_energy_nonzero_shows_warning(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 0, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Al día'],
            ['period' => '202606', 'amount' => 20, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Pendiente'],
        ], [], 'BELISARIO FRIAS');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'debt_free_aseo_with_energy_debt',
            'can_generate_paz_salvo' => true,
            'requires_energy_warning' => true,
            'aseo_balance' => 0.0,
            'energy_balance' => 20.0,
        ]);
    }

    public function test_san_miguelito_with_aseo_nonzero_blocks_generation(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 10, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Pendiente'],
        ], [], 'AMELIA DENIS DE ICAZA');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $this->assertResult($response, [
            'status' => 'has_aseo_debt',
            'can_generate_paz_salvo' => false,
            'requires_energy_warning' => false,
            'aseo_balance' => 10.0,
            'energy_balance' => 0.0,
        ]);
    }

    public function test_chilibre_with_energy_debt_is_not_san_miguelito(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response([
                'account' => ['client_number' => '822467', 'holder_name' => 'ZULEYKA ITZEL LORENZO', 'city' => 'CHILIBRE'],
                'balances' => ['total_balance' => 972.69, 'expired_balance' => 975.69, 'non_expired_balance' => -3],
                'debts' => [
                    ['document_type' => 'TOTAL A PAGAR', 'amount' => 972.69],
                    ['document_type' => 'Saldo de este mes Energía(JUN/2026)', 'amount' => -3],
                    ['document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'amount' => 0],
                    ['document_type' => 'Saldo a 30 días Energía(MAY/2026)', 'amount' => 0],
                    ['document_type' => 'Saldo a 30 días Aseo(MAY/2026)', 'amount' => 0],
                    ['document_type' => 'Saldo a Corte, 60 días o más Energía(ENE/2024)', 'amount' => 975.69],
                    ['document_type' => 'Saldo a 60 días o más Aseo(ENE/2024)', 'amount' => 0],
                ],
            ]),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '822467']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
    }

    public function test_pacora_with_empty_debts_is_not_san_miguelito(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response([
                'account' => ['client_number' => '99999', 'holder_name' => 'CLIENTE TEST', 'city' => 'PACORA'],
                'balances' => ['total_balance' => 0],
                'debts' => [],
            ]),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '99999']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'not_san_miguelito');
        $response->assertSessionHas('result.can_generate_paz_salvo', false);
        $response->assertSessionHas('result.requires_energy_warning', false);
    }

    public function test_total_a_pagar_does_not_count_as_aseo(): void
    {
        Http::fake([
            'utilitygo.widergy.com/*' => Http::response(['job_id' => 'job-1']),
            'utilitygo-api-4.widergy.com/*' => Http::response([
                'account' => ['client_number' => '34787', 'holder_name' => 'CLIENTE TEST', 'city' => 'BELISARIO FRIAS'],
                'balances' => ['total_balance' => 500],
                'debts' => [
                    ['document_type' => 'TOTAL A PAGAR', 'amount' => 500],
                    ['document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'amount' => 0],
                ],
            ]),
        ]);
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'debt_free');
        $response->assertSessionHas('result.balances.aseo_balance', 0.0);
    }

    public function test_negative_aseo_amount_is_ignored(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => -50, 'document_type' => 'Saldo de este mes Aseo(JUN/2026)', 'status' => 'Crédito'],
        ], [], 'BELISARIO FRIAS');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'debt_free');
        $response->assertSessionHas('result.balances.aseo_balance', 0.0);
    }

    public function test_energy_amount_does_not_count_as_aseo(): void
    {
        $this->fakeWidergy([
            ['period' => '202606', 'amount' => 100, 'document_type' => 'Saldo de este mes Energía(JUN/2026)', 'status' => 'Pendiente'],
        ], [], 'BELISARIO FRIAS');
        $response = $this->actingAs($this->authedUser())->post(route('paz-salvo.consult'), ['client_number' => '34787']);
        $response->assertRedirect();
        $response->assertSessionHas('result.status', 'debt_free_aseo_with_energy_debt');
        $response->assertSessionHas('result.balances.aseo_balance', 0.0);
        $response->assertSessionHas('result.balances.energy_balance', 100.0);
    }
}
