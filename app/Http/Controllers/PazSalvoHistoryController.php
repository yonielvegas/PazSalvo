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
        $documents = PazSalvo::query()->with(['generatedBy:id,name', 'agency:id,name'])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('folio', 'ilike', "%{$v}%")->orWhere('client_number', 'ilike', "%{$v}%")->orWhere('holder_name', 'ilike', "%{$v}%")))
            ->when($filters['agency_id'] ?? null, fn ($q, $v) => $q->where('agency_id', $v))
            ->when($filters['generated_by'] ?? null, fn ($q, $v) => $q->where('generated_by', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('issued_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('issued_at', '<=', $v))
            ->latest('issued_at')->paginate(15)->withQueryString()->through(function (PazSalvo $document) {
                $document->setAttribute('effective_status', $document->publicStatus());

                return $document;
            });

        return Inertia::render('paz-salvo/history', [
            'documents' => $documents, 'filters' => $filters,
            'agencies' => Agency::orderBy('name')->get(['id', 'name']), 'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(PazSalvo $pazSalvo): Response
    {
        $pazSalvo->load(['generatedBy:id,name', 'agency:id,name', 'cancelledBy:id,name'])->setAttribute('effective_status', $pazSalvo->publicStatus());

        return Inertia::render('paz-salvo/show', ['document' => $pazSalvo]);
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
