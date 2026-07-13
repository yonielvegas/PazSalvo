<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\GeneralAdminSignature;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        $activeSessions = DB::table('sessions')
            ->select('user_id', DB::raw('count(*) as active_session_count'), DB::raw('max(last_activity) as active_session_last_activity'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return Inertia::render('admin/users/index', [
            'users' => User::with('agency:id,name')->withCount('generatedPazSalvos')->orderBy('name')->get()->map(function (User $user) use ($activeSessions) {
                $session = $activeSessions->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'agency_id' => $user->agency_id,
                    'agency' => $user->agency?->name,
                    'roles' => $user->getRoleNames()->values(),
                    'has_active_general_admin_signature' => $user->activeGeneralAdminSignature()->exists(),
                    'has_any_general_admin_signature' => $user->generalAdminSignatures()->exists(),
                    'login_attempts' => $user->login_attempts,
                    'is_login_blocked' => $user->is_login_blocked,
                    'has_active_session' => $session !== null,
                    'active_session_count' => (int) ($session->active_session_count ?? 0),
                    'active_session_last_activity' => isset($session->active_session_last_activity) ? (int) $session->active_session_last_activity : null,
                    'generated_paz_salvos_count' => $user->generated_paz_salvos_count,
                ];
            }),
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'agency_id' => ['required', 'exists:agencies,id'],
            'role' => ['required', 'exists:roles,name'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
            'is_active' => ['boolean'],
            'general_admin_signature' => ['required_if:role,administrador_general', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $data['is_active'] ??= true;
        $role = $data['role'];
        unset($data['role']);

        if ($role === 'administrador_general') {
            $existingGeneralAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
                ->where('is_active', true)
                ->first();

            if ($existingGeneralAdmin && !$request->boolean('confirm_replace')) {
                return back()->withErrors([
                    'role' => 'Ya existe un Administrador General activo.',
                    'needs_general_admin_replace_confirmation' => 'true',
                    'current_general_admin_name' => $existingGeneralAdmin->name,
                ]);
            }

            if ($existingGeneralAdmin && $request->boolean('confirm_replace')) {
                GeneralAdminSignature::where('user_id', $existingGeneralAdmin->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Reemplazado por nuevo Administrador General.',
                    ]);

                $existingGeneralAdmin->update(['is_active' => false]);
            }

            unset($data['general_admin_signature']);
        }

        $user = User::create($data);
        $user->syncRoles([$role]);

        if ($role === 'administrador_general' && $request->hasFile('general_admin_signature')) {
            $signaturePath = $request->file('general_admin_signature')->store(
                'general-admin-signatures/' . $user->id,
                config('paz-salvo.disk')
            );

            GeneralAdminSignature::create([
                'user_id' => $user->id,
                'signature_path' => $signaturePath,
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);
        }

        return back()->with('message', 'Usuario creado correctamente.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'agency_id' => ['required', 'exists:agencies,id'],
            'role' => ['required', 'exists:roles,name'],
            'password' => ['nullable', 'string', 'confirmed', 'min:8'],
            'is_active' => ['boolean'],
            'general_admin_signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $newRole = $data['role'];
        $wasGeneralAdmin = $user->hasRole('administrador_general');
        $isGeneralAdmin = $newRole === 'administrador_general';
        $hasActiveGeneralAdminSignature = $user->activeGeneralAdminSignature()->exists();

        unset($data['role']);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($isGeneralAdmin) {
            $existingGeneralAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingGeneralAdmin && !$request->boolean('confirm_replace')) {
                return back()->withErrors([
                    'role' => 'Ya existe un Administrador General activo.',
                    'needs_general_admin_replace_confirmation' => 'true',
                    'current_general_admin_name' => $existingGeneralAdmin->name,
                ]);
            }

            if ($existingGeneralAdmin && $request->boolean('confirm_replace')) {
                GeneralAdminSignature::where('user_id', $existingGeneralAdmin->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Reemplazado por nuevo Administrador General.',
                    ]);

                $existingGeneralAdmin->update(['is_active' => false]);
            }

            if ($request->hasFile('general_admin_signature')) {
                if ($hasActiveGeneralAdminSignature) {
                    $user->activeGeneralAdminSignature()->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Firma reemplazada por el administrador.',
                    ]);
                }

                $signaturePath = $request->file('general_admin_signature')->store(
                    'general-admin-signatures/' . $user->id,
                    config('paz-salvo.disk')
                );

                GeneralAdminSignature::create([
                    'user_id' => $user->id,
                    'signature_path' => $signaturePath,
                    'is_active' => true,
                    'created_by' => $request->user()->id,
                ]);
            }

            $hasActiveAfterSave = $user->fresh()->activeGeneralAdminSignature()->exists();
            if (!$hasActiveAfterSave && !$request->hasFile('general_admin_signature')) {
                return back()->withErrors(['general_admin_signature' => 'La foto de firma es obligatoria para el rol Administrador General.']);
            }

            unset($data['general_admin_signature']);
        } elseif ($wasGeneralAdmin) {
            $user->activeGeneralAdminSignature()?->update([
                'is_active' => false,
                'deactivated_by' => $request->user()->id,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Rol Administrador General retirado.',
            ]);
        }

        $user->update($data);
        $user->syncRoles([$newRole]);

        return back()->with('message', 'Usuario actualizado correctamente.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'No puedes desactivar tu propio usuario.']);
        }

        $isActivating = !$user->is_active;

        if ($isActivating && $user->isGeneralAdmin()) {
            if (!$user->generalAdminSignatures()->exists()) {
                return back()->withErrors(['user' => 'Este Administrador General no tiene una firma registrada. Edita el usuario y sube una firma antes de activarlo.']);
            }

            $existingGeneralAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingGeneralAdmin && !$request->boolean('confirm_replace')) {
                return back()->withErrors([
                    'user' => 'Ya existe un Administrador General activo.',
                    'needs_general_admin_replace_confirmation' => 'true',
                    'current_general_admin_name' => $existingGeneralAdmin->name,
                    'activation_replace' => 'true',
                ]);
            }

            if ($existingGeneralAdmin && $request->boolean('confirm_replace')) {
                $existingGeneralAdmin->update(['is_active' => false]);

                GeneralAdminSignature::where('user_id', $existingGeneralAdmin->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Reemplazado por reactivación de Administrador General anterior.',
                    ]);
            }

            $user->update(['is_active' => true]);

            $lastSignature = $user->generalAdminSignatures()->latest('id')->first();
            if ($lastSignature && !$lastSignature->is_active) {
                $lastSignature->update(['is_active' => true]);
            }

            return back()->with('message', 'Usuario activado correctamente.');
        }

        $user->update(['is_active' => !$user->is_active]);

        if (!$user->is_active && $user->isGeneralAdmin()) {
            $user->activeGeneralAdminSignature()?->update([
                'is_active' => false,
                'deactivated_by' => $request->user()->id,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Usuario desactivado.',
            ]);
        }

        $message = $user->is_active ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.';
        return back()->with('message', $message);
    }

    public function signature(User $user): BinaryFileResponse
    {
        $generalAdminSignature = $user->activeGeneralAdminSignature()->first()
            ?? $user->generalAdminSignatures()->latest('id')->first();

        abort_unless($generalAdminSignature && Storage::disk(config('paz-salvo.disk'))->exists($generalAdminSignature->signature_path), 404);

        return response()->file(Storage::disk(config('paz-salvo.disk'))->path($generalAdminSignature->signature_path));
    }

    public function unlockLoginAttempts(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        if (! $user->is_login_blocked && (int) $user->login_attempts === 0) {
            return back()->with('message', 'El usuario no tiene bloqueos activos por intentos fallidos.');
        }

        $user->forceFill([
            'is_login_blocked' => false,
            'login_attempts' => 0,
        ])->save();

        $audit->record('login.unblocked', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'is_active' => $user->is_active,
        ], $user, $request);

        return back()->with('message', 'Login desbloqueado correctamente.');
    }

    public function releaseActiveSession(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();

        if ($deleted === 0) {
            return back()->with('message', 'El usuario no tiene sesión activa.');
        }

        $audit->record('user.session_released', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'sessions_deleted' => $deleted,
            'is_active' => $user->is_active,
        ], $user, $request);

        return back()->with('message', 'Sesión activa liberada correctamente.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->generatedPazSalvos()->exists()) {
            return back()->withErrors(['user' => 'No se puede borrar un usuario con certificados generados.']);
        }

        $user->delete();

        return back()->with('message', 'Usuario eliminado.');
    }
}
