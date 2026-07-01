<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    public function index(): Response
    {
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
        $data = $request->validate(['permissions' => ['array'], 'permissions.*' => ['exists:permissions,name']]);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('message', 'Permisos actualizados.');
    }
}
