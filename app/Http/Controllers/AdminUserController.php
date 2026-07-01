<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return Inertia::render('admin/users/index', [
            'users' => User::with('agency:id,name')->withCount('generatedPazSalvos')->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'agency_id' => $user->agency_id,
                'agency' => $user->agency?->name,
                'roles' => $user->getRoleNames()->values(),
                'has_active_signature' => $user->activeSignature()->exists(),
                'has_any_signature' => $user->userSignatures()->exists(),
                'generated_paz_salvos_count' => $user->generated_paz_salvos_count,
            ]),
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
            'signature' => ['required_if:role,supervisor', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $data['is_active'] ??= true;
        $role = $data['role'];
        unset($data['role']);

        if ($role === 'supervisor') {
            $existingSupervisor = User::whereHas('roles', fn ($q) => $q->where('name', 'supervisor'))
                ->where('agency_id', $data['agency_id'])
                ->where('is_active', true)
                ->first();

            if ($existingSupervisor && !$request->boolean('confirm_replace')) {
                return back()->withErrors([
                    'role' => 'Esta agencia ya tiene un supervisor activo.',
                    'needs_replace_confirmation' => 'true',
                    'current_supervisor_name' => $existingSupervisor->name,
                ]);
            }

            if ($existingSupervisor && $request->boolean('confirm_replace')) {
                UserSignature::where('user_id', $existingSupervisor->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Reemplazado por nuevo jefe de agencia.',
                    ]);

                $existingSupervisor->update(['is_active' => false]);
            }

            unset($data['signature']);
        }

        $user = User::create($data);
        $user->syncRoles([$role]);

        if ($role === 'supervisor' && $request->hasFile('signature')) {
            $signaturePath = $request->file('signature')->store(
                'user-signatures/' . $user->id,
                config('paz-salvo.disk')
            );

            UserSignature::create([
                'user_id' => $user->id,
                'agency_id' => $data['agency_id'],
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
            'signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $newRole = $data['role'];
        $wasSupervisor = $user->hasRole('supervisor');
        $isSupervisor = $newRole === 'supervisor';
        $hasActiveSignature = $user->activeSignature()->exists();
        $agencyChanged = $user->agency_id != $data['agency_id'];

        unset($data['role']);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($isSupervisor) {
            $existingSupervisor = User::whereHas('roles', fn ($q) => $q->where('name', 'supervisor'))
                ->where('agency_id', $data['agency_id'])
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingSupervisor && !$request->boolean('confirm_replace')) {
                return back()->withErrors([
                    'role' => 'Esta agencia ya tiene un supervisor activo.',
                    'needs_replace_confirmation' => 'true',
                    'current_supervisor_name' => $existingSupervisor->name,
                ]);
            }

            if ($existingSupervisor && $request->boolean('confirm_replace')) {
                UserSignature::where('user_id', $existingSupervisor->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Reemplazado por nuevo jefe de agencia.',
                    ]);

                $existingSupervisor->update(['is_active' => false]);
            }

            if ($wasSupervisor && $agencyChanged) {
                $user->activeSignature()?->update([
                    'is_active' => false,
                    'deactivated_by' => $request->user()->id,
                    'deactivated_at' => now(),
                    'deactivation_reason' => 'Agencia cambiada.',
                ]);
            }

            if ($request->hasFile('signature')) {
                if ($hasActiveSignature) {
                    $user->activeSignature()->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Firma reemplazada por el administrador.',
                    ]);
                }

                $signaturePath = $request->file('signature')->store(
                    'user-signatures/' . $user->id,
                    config('paz-salvo.disk')
                );

                UserSignature::create([
                    'user_id' => $user->id,
                    'agency_id' => $data['agency_id'],
                    'signature_path' => $signaturePath,
                    'is_active' => true,
                    'created_by' => $request->user()->id,
                ]);
            }

            $hasActiveAfterSave = $user->fresh()->activeSignature()->exists();
            if (!$hasActiveAfterSave && !$request->hasFile('signature')) {
                return back()->withErrors(['signature' => 'La foto de firma es obligatoria para el rol supervisor.']);
            }

            unset($data['signature']);
        } elseif ($wasSupervisor) {
            $user->activeSignature()?->update([
                'is_active' => false,
                'deactivated_by' => $request->user()->id,
                'deactivated_at' => now(),
                'deactivation_reason' => 'Rol supervisor retirado.',
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

        if ($isActivating && $user->isSupervisor()) {
            if (!$user->userSignatures()->exists()) {
                return back()->withErrors(['user' => 'Este supervisor no tiene una firma registrada. Edita el usuario y sube una firma antes de activarlo.']);
            }

            $existingSupervisor = User::whereHas('roles', fn ($q) => $q->where('name', 'supervisor'))
                ->where('agency_id', $user->agency_id)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingSupervisor && !$request->boolean('confirm_replace')) {
                return back()->withErrors([
                    'user' => 'Esta agencia ya tiene un jefe activo.',
                    'needs_replace_confirmation' => 'true',
                    'current_supervisor_name' => $existingSupervisor->name,
                    'activation_replace' => 'true',
                ]);
            }

            if ($existingSupervisor && $request->boolean('confirm_replace')) {
                $existingSupervisor->update(['is_active' => false]);

                UserSignature::where('user_id', $existingSupervisor->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'deactivated_by' => $request->user()->id,
                        'deactivated_at' => now(),
                        'deactivation_reason' => 'Reemplazado por reactivación de supervisor anterior.',
                    ]);
            }

            $user->update(['is_active' => true]);

            $lastSignature = $user->userSignatures()->latest('id')->first();
            if ($lastSignature && !$lastSignature->is_active) {
                $lastSignature->update(['is_active' => true]);
            }

            return back()->with('message', 'Usuario activado correctamente.');
        }

        $user->update(['is_active' => !$user->is_active]);

        if (!$user->is_active && $user->isSupervisor()) {
            $user->activeSignature()?->update([
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
        $signature = $user->activeSignature()->first() ?? $user->userSignatures()->latest('id')->first();

        abort_unless($signature && Storage::disk(config('paz-salvo.disk'))->exists($signature->signature_path), 404);

        return response()->file(Storage::disk(config('paz-salvo.disk'))->path($signature->signature_path));
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
