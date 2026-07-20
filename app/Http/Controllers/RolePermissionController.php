<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-roles');

        return Inertia::render('admin/roles/index', [
            'roles' => Role::with('permissions:id,name')->orderBy('name')->get(['id', 'name'])->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]),
            'permissions' => Permission::orderBy('name')->pluck('name'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        Gate::authorize('manage-roles');
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['exists:permissions,name']]);
        DB::transaction(function () use ($role, $data, $request) {
            $before = $role->permissions()->pluck('name')->all();
            $role->syncPermissions($data['permissions'] ?? []);
            User::role($role->name)->increment('session_version');
            app(AuditLogger::class)->record('role.permissions_updated', [
                'role' => $role->name,
                'before' => $before,
                'after' => $role->fresh()->permissions()->pluck('name')->all(),
            ], $role, $request, 'success');
        });

        return back()->with('message', 'Permisos actualizados.');
    }
}
