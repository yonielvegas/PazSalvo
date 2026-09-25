<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    private const RESERVED_ROLES = ['admin', 'supervisor', 'operador', 'consulta', 'administrador_general'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $counts = DB::table('model_has_roles')->where('model_type', User::class)
            ->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');
        $roles = Role::with('permissions:id,name')->when($search !== '', fn ($query) => $query->where('name', 'ilike', "%{$search}%"))
            ->orderBy('name')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_active' => filter_var($role->is_active, FILTER_VALIDATE_BOOLEAN),
                'users_count' => (int) ($counts[$role->id] ?? 0),
                'permissions_count' => $role->permissions->count(),
                'permissions' => $role->permissions->pluck('name')->values(),
                'is_reserved' => in_array($role->name, self::RESERVED_ROLES, true),
            ]);

        $labels = [
            'consultar paz y salvo' => ['Paz y Salvo', 'Consultar'],
            'generar paz y salvo' => ['Paz y Salvo', 'Generar'],
            'ver historial' => ['Historial', 'Ver historial'],
            'ver detalle paz y salvo' => ['Historial', 'Ver detalle'],
            'anular paz y salvo' => ['Historial', 'Anular'],
            'administrar usuarios' => ['Usuarios', 'Administrar'],
            'administrar agencias' => ['Agencias (legado)', 'Administrar'],
            'administrar roles' => ['Roles (legado)', 'Administrar'],
            'clients-excel.view' => ['Excel de Clientes', 'Ver y cargar'],
            'clients-excel.download' => ['Excel de Clientes', 'Descargar'],
            'clients-excel.delete' => ['Excel de Clientes', 'Eliminar'],
            'settings.view' => ['Configuración', 'Acceder'],
            'settings.agencies.view' => ['Configuración · Agencias', 'Ver'],
            'settings.agencies.create' => ['Configuración · Agencias', 'Crear'],
            'settings.agencies.update' => ['Configuración · Agencias', 'Editar'],
            'settings.agencies.disable' => ['Configuración · Agencias', 'Desactivar y reactivar'],
            'settings.roles.view' => ['Configuración · Roles', 'Ver'],
            'settings.roles.create' => ['Configuración · Roles', 'Crear'],
            'settings.roles.update' => ['Configuración · Roles', 'Editar'],
            'settings.roles.permissions' => ['Configuración · Roles', 'Administrar permisos'],
            'settings.roles.disable' => ['Configuración · Roles', 'Desactivar y reactivar'],
        ];

        return Inertia::render('settings/roles/index', [
            'roles' => $roles,
            'permissions' => Permission::orderBy('name')->pluck('name')->map(fn (string $name) => [
                'name' => $name,
                'group' => $labels[$name][0] ?? 'Otros',
                'label' => $labels[$name][1] ?? $name,
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(RoleRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        abort_if(($data['permissions'] ?? []) !== [] && ! $request->user()->can('settings.roles.permissions'), 403);
        $role = DB::transaction(function () use ($data, $request, $audit) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions'] ?? []);
            $audit->record('role.created', ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')->all()], $role, $request, 'success');

            return $role;
        });

        return redirect()->route('settings.roles.index')->with('message', "Rol {$role->name} creado correctamente.");
    }

    public function update(RoleRequest $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        if (in_array($role->name, self::RESERVED_ROLES, true) && $request->input('name') !== $role->name) {
            throw ValidationException::withMessages(['name' => 'El nombre de este rol del sistema no puede cambiarse.']);
        }
        DB::transaction(function () use ($request, $role, $audit): void {
            $before = $role->name;
            $role->update(['name' => $request->validated('name')]);
            $audit->record('role.updated', ['before' => $before, 'after' => $role->name], $role, $request, 'success');
        });

        return back()->with('message', 'Rol actualizado correctamente.');
    }

    public function permissions(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        if ($role->name === 'admin') {
            throw ValidationException::withMessages(['permissions' => 'Los permisos del rol admin se mantienen mediante el seeder de producción.']);
        }
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);
        DB::transaction(function () use ($role, $data, $request, $audit): void {
            $before = $role->permissions()->pluck('name')->all();
            $role->syncPermissions($data['permissions'] ?? []);
            User::role($role->name)->increment('session_version');
            $audit->record('role.permissions_updated', [
                'role' => $role->name,
                'before' => $before,
                'after' => $role->fresh()->permissions()->pluck('name')->all(),
            ], $role, $request, 'success');
        });

        return back()->with('message', 'Permisos actualizados.');
    }

    public function toggle(Request $request, Role $role, AuditLogger $audit): RedirectResponse
    {
        if ($role->name === 'admin') {
            throw ValidationException::withMessages(['role' => 'El rol admin no puede desactivarse.']);
        }
        $wasActive = filter_var($role->is_active, FILTER_VALIDATE_BOOLEAN);
        if ($wasActive && DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'No se puede desactivar un rol que tiene usuarios asignados.']);
        }
        DB::transaction(function () use ($request, $role, $audit, $wasActive): void {
            $role->forceFill(['is_active' => ! $wasActive])->save();
            $audit->record($wasActive ? 'role.disabled' : 'role.activated', [], $role, $request, 'success');
        });

        return back()->with('message', $wasActive ? 'Rol desactivado.' : 'Rol reactivado.');
    }
}
