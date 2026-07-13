<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PazSalvoHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'agency_id' => ['nullable', 'integer'],
            'generated_by' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['generated', 'cancelled', 'error'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $documents = PazSalvo::query()->with(['client:id,client_number,holder_name,district,corregimiento,city,address', 'generatedBy:id,name', 'agency:id,name'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('folio', 'ilike', "%{$v}%")->orWhereHas('client', fn ($q) => $q->where('client_number', 'ilike', "%{$v}%")->orWhere('holder_name', 'ilike', "%{$v}%"))))
            ->when($filters['agency_id'] ?? null, fn ($q, $v) => $q->where('agency_id', $v))
            ->when($filters['generated_by'] ?? null, fn ($q, $v) => $q->where('generated_by', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('issued_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('issued_at', '<=', $v))
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
            'agencies' => Agency::orderBy('name')->get(['id', 'name']), 'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(PazSalvo $pazSalvo): Response
    {
        $pazSalvo->load(['client', 'generatedBy:id,name', 'agency:id,name', 'generalAdminSignature.user:id,name', 'cancelledBy:id,name']);
        $document = [
            'id' => $pazSalvo->id,
            'folio' => $pazSalvo->folio,
            'numero_factura' => $pazSalvo->numero_factura,
            'verification_token' => $pazSalvo->verification_token,
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
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $updated = PazSalvo::whereKey($pazSalvo->id)->where('status', PazSalvo::GENERATED)->update([
            'status' => PazSalvo::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id,
            'cancel_reason' => $data['cancel_reason'], 'updated_at' => now(),
        ]);
        if (! $updated) {
            return back()->withErrors(['cancel_reason' => 'Este certificado no puede anularse o ya fue anulado.']);
        }

        return back()->with('message', 'Certificado anulado correctamente.');
    }
}
