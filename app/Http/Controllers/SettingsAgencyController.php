<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgencyRequest;
use App\Models\Agency;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsAgencyController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $query = Agency::query()->withCount('users');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        if ($status !== 'all') {
            $query->where('is_active', $status === 'active');
        }

        return Inertia::render('settings/agencies/index', [
            'agencies' => $query->orderBy('name')->get()->map(fn (Agency $agency) => [
                'id' => $agency->id,
                'code' => $agency->code,
                'name' => $agency->name,
                'address' => $agency->address,
                'is_active' => $agency->is_active,
                'users_count' => $agency->users_count,
                'created_at' => $agency->created_at->timezone(config('app.timezone'))->format('d/m/Y'),
            ]),
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function store(AgencyRequest $request, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $audit): void {
            $agency = Agency::create([...$request->validated(), 'is_active' => true]);
            $audit->record('agency.created', ['code' => $agency->code], $agency, $request, 'success');
        });

        return back()->with('message', 'Agencia creada correctamente.');
    }

    public function update(AgencyRequest $request, Agency $agency, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $agency, $audit): void {
            $before = $agency->only(['code', 'name', 'address']);
            $agency->update($request->validated());
            $audit->record('agency.updated', ['before' => $before, 'after' => $agency->only(['code', 'name', 'address'])], $agency, $request, 'success');
        });

        return back()->with('message', 'Agencia actualizada correctamente.');
    }

    public function toggle(Request $request, Agency $agency, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $agency, $audit): void {
            $agency->update(['is_active' => ! $agency->is_active]);
            $audit->record($agency->is_active ? 'agency.activated' : 'agency.disabled', [], $agency, $request, 'success');
        });

        return back()->with('message', $agency->is_active ? 'Agencia reactivada.' : 'Agencia desactivada.');
    }
}
