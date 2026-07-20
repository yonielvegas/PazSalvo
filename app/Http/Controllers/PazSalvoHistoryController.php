<?php

namespace App\Http\Controllers;

use App\Models\PazSalvo;
use App\Services\AuditLogger;
use App\Services\PublicVerificationUrlBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PazSalvoHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PazSalvo::class);
        $filters = $request->validate([
            'folio' => ['nullable', 'string', 'max:30'],
            'nac' => ['nullable', 'string', 'regex:/^\d+$/', 'max:30'],
            'numero_factura' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'titular' => ['nullable', 'string', 'max:150'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
        ]);
        $filters = $this->normalizeFilters($filters);

        $documents = PazSalvo::query()->with(['client:id,client_number,holder_name,district,corregimiento,city,address', 'generatedBy:id,name', 'agency:id,name'])
            ->when($filters['folio'] ?? null, fn (Builder $q, string $v) => $q->where('folio', 'ilike', $this->like($v)))
            ->when($filters['nac'] ?? null, fn (Builder $q, string $v) => $q->whereHas('client', fn (Builder $q) => $q->where('client_number', $v)))
            ->when($filters['numero_factura'] ?? null, function (Builder $q, string $v): void {
                $v = strlen($v) === 6 ? $v : $this->like($v);
                $operator = strlen($v) === 6 ? '=' : 'ilike';
                $q->where('numero_factura', $operator, $v);
            })
            ->when($filters['titular'] ?? null, fn (Builder $q, string $v) => $q->whereHas('client', fn (Builder $q) => $q->where('holder_name', 'ilike', $this->like($v))))
            ->when($filters['fecha_desde'] ?? null, fn (Builder $q, string $v) => $q->where('issued_at', '>=', Carbon::createFromFormat('Y-m-d', $v, 'America/Panama')->startOfDay()->utc()))
            ->when($filters['fecha_hasta'] ?? null, fn (Builder $q, string $v) => $q->where('issued_at', '<', Carbon::createFromFormat('Y-m-d', $v, 'America/Panama')->addDay()->startOfDay()->utc()))
            ->latest('issued_at')->paginate(15)->withQueryString()->through(function (PazSalvo $document) {
                return [
                    'id' => $document->id,
                    'folio' => $document->folio,
                    'numero_factura' => $document->numero_factura,
                    'client_number' => $document->client->client_number,
                    'holder_name' => $document->client->holder_name,
                    'agency_name' => $document->agency->name,
                    'generated_by_name' => $document->generatedBy->name,
                    'issued_at' => $document->issued_at,
                    'expires_at' => $document->expires_at,
                    'status' => $document->status,
                    'effective_status' => $document->publicStatus(),
                ];
            });

        return Inertia::render('paz-salvo/history', [
            'documents' => $documents, 'filters' => $filters,
        ]);
    }

    private function normalizeFilters(array $filters): array
    {
        foreach (['folio', 'nac', 'numero_factura', 'titular'] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $filters[$key] = preg_replace('/\s+/', ' ', trim((string) $filters[$key]));
            if ($filters[$key] === '') {
                unset($filters[$key]);
            }
        }

        if (isset($filters['folio'])) {
            $filters['folio'] = mb_strtoupper($filters['folio']);
        }

        foreach (['fecha_desde', 'fecha_hasta'] as $key) {
            if (($filters[$key] ?? null) === '' || ($filters[$key] ?? null) === null) {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    public function show(PazSalvo $pazSalvo, PublicVerificationUrlBuilder $urlBuilder): Response
    {
        Gate::authorize('view', $pazSalvo);
        $pazSalvo->load(['client', 'generatedBy:id,name', 'agency:id,name', 'generalAdminSignature.user:id,name', 'cancelledBy:id,name']);
        $document = [
            'id' => $pazSalvo->id,
            'folio' => $pazSalvo->folio,
            'numero_factura' => $pazSalvo->numero_factura,
            'public_verification_url' => $urlBuilder->build($pazSalvo->verification_token),
            'status' => $pazSalvo->status,
            'effective_status' => $pazSalvo->publicStatus(),
            'client_number' => $pazSalvo->client->client_number,
            'holder_name' => $pazSalvo->client->holder_name,
            'full_address' => $pazSalvo->client->full_address,
            'total_balance' => $pazSalvo->total_balance,
            'issued_at' => $pazSalvo->issued_at,
            'expires_at' => $pazSalvo->expires_at,
            'agency_name' => $pazSalvo->agency->name,
            'generated_by_name' => $pazSalvo->generatedBy->name,
            'authorized_by_name' => $pazSalvo->generalAdminSignature?->user?->name,
            'cancelled_at' => $pazSalvo->cancelled_at,
            'cancel_reason' => $pazSalvo->cancel_reason,
            'cancelled_by' => $pazSalvo->cancelledBy,
        ];

        return Inertia::render('paz-salvo/show', ['document' => $document]);
    }

    public function cancel(Request $request, PazSalvo $pazSalvo): RedirectResponse
    {
        Gate::authorize('cancel', $pazSalvo);
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $updated = DB::transaction(function () use ($pazSalvo, $request, $data) {
            $document = PazSalvo::whereKey($pazSalvo->id)->lockForUpdate()->firstOrFail();
            if ($document->status !== PazSalvo::GENERATED) {
                return false;
            }

            return $document->update([
                'status' => PazSalvo::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id,
                'cancel_reason' => $data['cancel_reason'], 'updated_at' => now(),
            ]);
        });
        if (! $updated) {
            return back()->withErrors(['cancel_reason' => 'Este certificado no puede anularse o ya fue anulado.']);
        }
        app(AuditLogger::class)->record('paz_salvo.cancelled', [
            'folio' => $pazSalvo->folio,
            'reason' => $data['cancel_reason'],
        ], $pazSalvo, $request, 'success');

        return back()->with('message', 'Certificado anulado correctamente.');
    }
}
