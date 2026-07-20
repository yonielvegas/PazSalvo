<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Client;
use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HistoryInvoiceSearchTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    private function historyUser(): User
    {
        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ver historial', 'guard_name' => 'web']);
        $user->givePermissionTo('ver historial');

        return $user;
    }

    private function document(array $attributes = [], array $clientAttributes = []): PazSalvo
    {
        $clientId = $attributes['client_id'] ?? null;
        unset($attributes['client_id']);
        $client = $clientId ? Client::findOrFail($clientId) : Client::factory()->create($clientAttributes);
        $agency = Agency::factory()->create();
        $generator = User::factory()->create(['agency_id' => $agency->id]);
        $signature = GeneralAdminSignature::first();

        if (! $signature) {
            $generalAdmin = User::factory()->create();
            $signature = GeneralAdminSignature::create([
                'user_id' => $generalAdmin->id,
                'signature_path' => 'templates/assets/Firma.jpeg',
                'is_active' => true,
                'created_by' => $generator->id,
            ]);
        }
        $number = $this->sequence++;

        return PazSalvo::create([
            'sequence_number' => $number,
            'sequence_year' => 2026,
            'folio' => sprintf('CC-%06d-2026', $number),
            'verification_token' => (string) Str::uuid(),
            'client_id' => $client->id,
            'generated_by' => $generator->id,
            'agency_id' => $agency->id,
            'general_admin_signature_id' => $signature->id,
            'total_balance' => 0,
            'issued_at' => now()->addSeconds($number),
            'expires_at' => now()->addDays(30),
            'status' => PazSalvo::GENERATED,
            ...$attributes,
        ]);
    }

    public function test_history_filters_exclusively_by_folio(): void
    {
        $user = $this->historyUser();
        $expected = $this->document(['folio' => 'CC-000008-2026']);
        $this->document(['numero_factura' => '000008'], ['client_number' => '000008']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['folio' => 'cc-000008']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
                ->where('filters.folio', 'CC-000008')
            );
    }

    public function test_history_filters_exclusively_by_nac(): void
    {
        $user = $this->historyUser();
        $expected = $this->document(['numero_factura' => '999999'], ['client_number' => '123456']);
        $this->document(['numero_factura' => '123456'], ['client_number' => '654321']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['nac' => '123456']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
                ->where('documents.data.0.client_number', '123456')
            );
    }

    public function test_history_filters_exclusively_by_invoice_number(): void
    {
        $user = $this->historyUser();
        $expected = $this->document(['numero_factura' => '123456'], ['client_number' => '654321']);
        $this->document(['numero_factura' => '999999'], ['client_number' => '123456']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['numero_factura' => '123456']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
                ->where('documents.data.0.numero_factura', '123456')
            );
    }

    public function test_history_filters_exclusively_by_holder_name(): void
    {
        $user = $this->historyUser();
        $expected = $this->document([], ['holder_name' => 'Titular Especial']);
        $this->document(['folio' => 'CC-TITULAR-2026'], ['holder_name' => 'Otra Persona']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['titular' => 'titular especial']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
            );
    }

    public function test_history_filters_by_generation_date_range(): void
    {
        $user = $this->historyUser();
        $this->document(['issued_at' => '2026-07-01 14:00:00']);
        $expected = $this->document(['issued_at' => '2026-07-10 23:30:00']);
        $this->document(['issued_at' => '2026-07-12 00:00:00']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['fecha_desde' => '2026-07-10', 'fecha_hasta' => '2026-07-10']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
            );
    }

    public function test_same_value_for_nac_and_invoice_does_not_mix_results(): void
    {
        $user = $this->historyUser();
        $byNac = $this->document(['numero_factura' => '999999'], ['client_number' => '123456']);
        $byInvoice = $this->document(['numero_factura' => '123456'], ['client_number' => '654321']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['nac' => '123456']))
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $byNac->id)
            );

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['numero_factura' => '123456']))
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $byInvoice->id)
            );
    }

    public function test_combining_nac_and_invoice_uses_and_logic(): void
    {
        $user = $this->historyUser();
        $client = Client::factory()->create(['client_number' => '610479']);
        $expected = $this->document(['client_id' => $client->id, 'numero_factura' => '000123']);
        $this->document(['numero_factura' => '000123'], ['client_number' => '999999']);
        $this->document(['client_id' => $client->id, 'numero_factura' => '999999']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['nac' => '610479', 'numero_factura' => '000123']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
            );
    }

    public function test_combining_folio_and_date_uses_and_logic(): void
    {
        $user = $this->historyUser();
        $expected = $this->document(['folio' => 'CC-000008-2026', 'issued_at' => '2026-07-15 10:00:00']);
        $this->document(['folio' => 'CC-000008-2025', 'issued_at' => '2026-07-10 10:00:00']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['folio' => 'CC-000008', 'fecha_desde' => '2026-07-15', 'fecha_hasta' => '2026-07-15']))
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
            );
    }

    public function test_invoice_filter_preserves_leading_zeroes(): void
    {
        $user = $this->historyUser();
        $expected = $this->document(['numero_factura' => '000123']);
        $this->document(['numero_factura' => '123000']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['numero_factura' => '000123']))
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 1)
                ->where('documents.data.0.id', $expected->id)
                ->where('filters.numero_factura', '000123')
            );
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->historyUser())
            ->get(route('paz-salvos.index', ['fecha_desde' => '2026-07-20', 'fecha_hasta' => '2026-07-01']))
            ->assertSessionHasErrors('fecha_hasta');
    }

    public function test_non_numeric_nac_and_invoice_are_rejected(): void
    {
        $user = $this->historyUser();

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['nac' => '12A456']))
            ->assertSessionHasErrors('nac');

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['numero_factura' => '12A456']))
            ->assertSessionHasErrors('numero_factura');
    }

    public function test_sql_injection_input_does_not_alter_the_query(): void
    {
        $this->document(['numero_factura' => '777777'], ['holder_name' => 'Persona Segura']);

        $this->actingAs($this->historyUser())
            ->get(route('paz-salvos.index', ['titular' => "%' OR 1=1 --"]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 0)
                ->has('documents.data', 0)
            );
    }

    public function test_pagination_links_preserve_specific_filters(): void
    {
        $user = $this->historyUser();

        for ($i = 100; $i < 116; $i++) {
            $this->document(['numero_factura' => sprintf('000%d', $i)]);
        }

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['numero_factura' => '0001']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $links = collect($page->toArray()['props']['documents']['links']);
                $this->assertTrue($links->contains(fn ($link) => is_string($link['url']) && str_contains($link['url'], 'numero_factura=0001')));
            });
    }

    public function test_empty_parameters_are_ignored(): void
    {
        $user = $this->historyUser();
        $first = $this->document();
        $second = $this->document();

        $this->actingAs($user)
            ->get(route('paz-salvos.index', ['folio' => '', 'nac' => '', 'numero_factura' => '', 'titular' => '']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 2)
                ->where('documents.data.0.id', $second->id)
                ->where('documents.data.1.id', $first->id)
            );
    }

    public function test_user_without_history_permission_cannot_access_history(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('paz-salvos.index'))
            ->assertForbidden();
    }

    public function test_status_filter_is_no_longer_accepted_or_returned(): void
    {
        $this->document(['status' => PazSalvo::GENERATED]);
        $this->document(['status' => PazSalvo::CANCELLED]);

        $this->actingAs($this->historyUser())
            ->get(route('paz-salvos.index', ['status' => 'generated']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.total', 2)
                ->missing('filters.status')
            );
    }

    public function test_history_order_remains_descending_by_issue_date(): void
    {
        $user = $this->historyUser();
        $older = $this->document(['issued_at' => '2026-07-01 10:00:00']);
        $newer = $this->document(['issued_at' => '2026-07-20 10:00:00']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('documents.data.0.id', $newer->id)
                ->where('documents.data.1.id', $older->id)
            );
    }

    public function test_history_payload_includes_invoice_number(): void
    {
        $user = $this->historyUser();
        $document = $this->document(['numero_factura' => '888888']);

        $this->actingAs($user)
            ->get(route('paz-salvos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.data.0.id', $document->id)
                ->where('documents.data.0.numero_factura', '888888')
            );
    }
}
