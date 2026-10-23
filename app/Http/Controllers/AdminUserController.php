<?php

namespace App\Http\Controllers;

use App\Http\Requests\SsoIdentityLinkRequest;
use App\Integrations\AaudAuth\Access\AccessDelivery;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Models\Agency;
use App\Models\GeneralAdminSignature;
use App\Models\Role;
use App\Models\User;
use App\Services\AdministrativeTransaction;
use App\Services\AuditLogger;
use App\Services\UserSsoIdentityManager;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-users');
        $activeSessions = DB::table('sessions')
            ->select('user_id', DB::raw('count(*) as active_session_count'), DB::raw('max(last_activity) as active_session_last_activity'))
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return Inertia::render('admin/users/index', [
            'users' => User::with(['agency:id,name', 'ssoIdentity:id,user_id,issuer,subject,created_at,verified_profile'])->withCount('generatedPazSalvos')->orderBy('name')->get()->map(function (User $user) use ($activeSessions) {
                $session = $activeSessions->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'is_active' => $user->is_active,
                    'sso_linked' => $user->ssoIdentity !== null,
                    'sso_sync' => config('aaud-auth.automatic') && DB::table('aaud_auth_access_targets')->where('application_user_id', (string) $user->id)->exists()
                        ? app(AccessDelivery::class)->message([(string) $user->id]) : null,
                    'sso_identity' => $user->ssoIdentity ? ['id' => $user->ssoIdentity->id, 'issuer' => $user->ssoIdentity->issuer,
                        'subject' => $user->ssoIdentity->subject, 'linked_at' => $user->ssoIdentity->created_at->toIso8601String(),
                        'verified_profile' => $user->ssoIdentity->verified_profile] : null,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'agency_id' => $user->agency_id,
                    'agency' => $user->agency?->name,
                    'role' => $user->hasOperationalRole() ? $user->roles()->value('name') : null,
                    'has_active_general_admin_signature' => $user->activeGeneralAdminSignature()->exists(),
                    'has_any_general_admin_signature' => $user->generalAdminSignatures()->exists(),
                    'login_attempts' => $user->login_attempts,
                    'is_login_blocked' => $user->is_login_blocked,
                    'must_change_password' => $user->must_change_password,
                    'has_active_session' => $session !== null,
                    'active_session_count' => (int) ($session->active_session_count ?? 0),
                    'active_session_last_activity' => isset($session->active_session_last_activity) ? (int) $session->active_session_last_activity : null,
                    'generated_paz_salvos_count' => $user->generated_paz_salvos_count,
                ];
            }),
            'sso_sync_attention_count' => config('aaud-auth.automatic')
                ? DB::table('aaud_auth_access_targets')->where('delivery_error', 'needs_review')->count() : 0,
            'agencies' => Agency::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::where('is_active', true)->orderBy('name')->pluck('name'),
            'temporary_password' => $this->temporaryPassword(),
            'sso_directory_available' => config('aaud-auth.enabled') === true && is_string(config('aaud-auth.issuer')) && config('aaud-auth.issuer') !== '',
        ]);
    }

    public function store(Request $request, UserSsoIdentityManager $identities): RedirectResponse
    {
        Gate::authorize('manage-users');
        $selection = $this->identitySelection($request);
        $create = function (array $profile = []) use ($request) {
            $request->merge($profile);
            $this->normalizeUsername($request);
            Gate::authorize('manage-users');
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'username' => $this->usernameRules(),
                'agency_id' => ['required', Rule::exists('agencies', 'id')->where('is_active', true)],
                'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')->where('is_active', true)],
                'is_active' => ['boolean'],
                'general_admin_signature' => ['required_if:role,administrador_general', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            ]);

            $user = AdministrativeTransaction::run(function () use ($request, $data) {
                $data['is_active'] ??= true;
                $role = $data['role'];
                unset($data['role'], $data['general_admin_signature']);

                if ($role === 'administrador_general') {
                    $existingGeneralAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if ($existingGeneralAdmin && ! $request->boolean('confirm_replace')) {
                        throw ValidationException::withMessages([
                            'role' => 'Ya existe un Administrador General activo.',
                            'needs_general_admin_replace_confirmation' => 'true',
                            'current_general_admin_name' => $existingGeneralAdmin->name,
                        ]);
                    }

                    if ($existingGeneralAdmin && $request->boolean('confirm_replace')) {
                        $this->deactivateGeneralAdmin($existingGeneralAdmin, $request);
                    }

                    unset($data['general_admin_signature']);
                }

                $data['password'] = Hash::make($this->temporaryPassword());
                $data['must_change_password'] = true;
                $data['password_reset_at'] = now();
                $data['password_reset_by'] = $request->user()->id;
                $data['password_changed_at'] = null;
                $data['login_attempts'] = 0;
                $data['is_login_blocked'] = false;
                $data['session_version'] = 0;
                $data['remember_token'] = null;

                $user = User::create($data);
                $user->syncRoles([$role]);

                if ($role === 'administrador_general' && $request->hasFile('general_admin_signature')) {
                    $signaturePath = $this->prepareSignature($request, $user);

                    GeneralAdminSignature::create([
                        'user_id' => $user->id,
                        'signature_path' => $signaturePath,
                        'is_active' => true,
                        'created_by' => $request->user()->id,
                    ]);
                }

                return $user;
            });

            app(AuditLogger::class)->record('user.created', [
                'target_user_id' => $user->id,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->all(),
            ], $user, $request, 'success');

            return $user;

        };
        try {
            $this->compensated(fn () => $selection === null
                ? AdministrativeTransaction::run($create)
                : $identities->createAndLink($request->user(), $selection, $request, $create), $request, 'user.store');
        } catch (AaudAuthRequestException|TechnicalAuthenticationException) {
            throw ValidationException::withMessages(['identity' => 'No se pudo verificar el directorio. No se creó el usuario. Puede cancelar la selección SSO y crear únicamente la cuenta local.']);
        }

        return back()->with('message', $selection === null ? 'Usuario creado correctamente.' : 'Usuario creado e identidad SSO vinculada correctamente.');
    }

    public function update(Request $request, User $user, UserSsoIdentityManager $identities): RedirectResponse
    {
        Gate::authorize('manage-users');
        $change = $request->validate(['sso_action' => ['sometimes', Rule::in(['keep', 'link', 'unlink'])],
            'sso_identity_id' => ['nullable', 'integer', 'min:1'], 'sso_unlink_confirmed' => ['sometimes', 'boolean']]);
        $selection = $this->identitySelection($request);
        $action = $change['sso_action'] ?? ($selection ? 'link' : 'keep');
        if (($action === 'link') !== ($selection !== null)) {
            throw ValidationException::withMessages(['identity' => 'Seleccione expresamente una identidad para vincular.']);
        }
        $operation = ['action' => $action, 'selection' => $selection, 'check_expected' => $request->exists('sso_identity_id'),
            'expected_id' => isset($change['sso_identity_id']) ? (int) $change['sso_identity_id'] : null,
            'confirmed' => $request->boolean('sso_unlink_confirmed')];
        $update = function (User $user, array $profile) use ($request) {
            $request->merge($profile);
            $this->normalizeUsername($request);
            Gate::authorize('manage-users');
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
                'username' => $this->usernameRules($user),
                'agency_id' => ['required', Rule::exists('agencies', 'id')->where('is_active', true)],
                'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')->where('is_active', true)],
                'is_active' => ['boolean'],
                'general_admin_signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            ]);

            $oldState = ['email' => $user->email, 'roles' => $user->getRoleNames()->all(), 'is_active' => $user->is_active];
            $newRole = $data['role'];
            AdministrativeTransaction::protectLastAdmin($user, $newRole === 'admin' && (bool) ($data['is_active'] ?? $user->is_active));
            $wasGeneralAdmin = $user->hasRole('administrador_general');
            $isGeneralAdmin = $newRole === 'administrador_general';
            $hasActiveGeneralAdminSignature = $user->activeGeneralAdminSignature()->exists();

            unset($data['role'], $data['general_admin_signature']);

            if ($isGeneralAdmin) {
                $existingGeneralAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
                    ->where('is_active', true)
                    ->where('id', '!=', $user->id)
                    ->first();

                if ($existingGeneralAdmin && ! $request->boolean('confirm_replace')) {
                    throw ValidationException::withMessages([
                        'role' => 'Ya existe un Administrador General activo.',
                        'needs_general_admin_replace_confirmation' => 'true',
                        'current_general_admin_name' => $existingGeneralAdmin->name,
                    ]);
                }

                if ($existingGeneralAdmin && $request->boolean('confirm_replace')) {
                    $this->deactivateGeneralAdmin($existingGeneralAdmin, $request);
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

                    $signaturePath = $this->prepareSignature($request, $user);

                    GeneralAdminSignature::create([
                        'user_id' => $user->id,
                        'signature_path' => $signaturePath,
                        'is_active' => true,
                        'created_by' => $request->user()->id,
                    ]);
                }

                $hasActiveAfterSave = $user->fresh()->activeGeneralAdminSignature()->exists();
                if (! $hasActiveAfterSave && ! $request->hasFile('general_admin_signature')) {
                    throw ValidationException::withMessages(['general_admin_signature' => 'La foto de firma es obligatoria para el rol Administrador General.']);
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

            AdministrativeTransaction::run(function () use ($user, $data, $newRole) {
                $data['session_version'] = ((int) $user->session_version) + 1;
                $user->syncRoles([$newRole]);
                $user->update($data);
            });

            app(AuditLogger::class)->record('user.updated', [
                'target_user_id' => $user->id,
                'before' => $oldState,
                'after' => ['email' => $user->fresh()->email, 'roles' => $user->fresh()->getRoleNames()->all(), 'is_active' => $user->fresh()->is_active],
            ], $user, $request, 'success');

            return $user;

        };
        try {
            $this->compensated(fn () => $identities->updateWithIdentity($user, $request->user(), $operation, $request, $update), $request, 'user.update');
        } catch (AaudAuthRequestException|TechnicalAuthenticationException) {
            throw ValidationException::withMessages(['identity' => 'No se pudo verificar AAUD AUTH. No se guardó ningún cambio.']);
        }

        return back()->with('message', match ($action) {
            'link' => 'Usuario e identidad AAUD AUTH guardados correctamente.',
            'unlink' => 'Usuario guardado e identidad AAUD AUTH desvinculada correctamente.',
            default => 'Usuario actualizado correctamente.',
        });
    }

    private function identitySelection(Request $request): ?array
    {
        if (! $request->exists('sso_identity')) {
            return null;
        }
        $rules = ['sso_identity' => ['required', 'array:issuer,subject,query,page,confirmed']];
        foreach (SsoIdentityLinkRequest::selectionRules() as $key => $constraints) {
            $rules['sso_identity.'.$key] = $constraints;
        }

        return $request->validate($rules)['sso_identity'];
    }

    private function normalizeUsername(Request $request): void
    {
        if ($request->exists('username') && (is_string($request->input('username')) || $request->input('username') === null)) {
            $request->merge(['username' => User::normalizeUsername($request->input('username'))]);
        }
    }

    private function usernameRules(?User $user = null): array
    {
        return ['sometimes', 'nullable', 'string', 'max:255', 'regex:/\A[^\x00-\x1f\x7f]+\z/uD', function ($attribute, $value, $fail) use ($user) {
            if (User::whereRaw('LOWER(username) = LOWER(?)', [$value])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists()) {
                $fail('El username ya pertenece a otro usuario.');
            }
        }];
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        return $this->administrative(function () use ($request, $user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage-users');
            if ($user->id === $request->user()->id) {
                throw ValidationException::withMessages(['user' => 'No puedes desactivar tu propio usuario.']);
            }

            $isActivating = ! $user->is_active;
            if ($isActivating && ! $user->hasOperationalRole()) {
                throw ValidationException::withMessages(['role' => User::NO_ROLE_LOGIN_MESSAGE]);
            }
            AdministrativeTransaction::protectLastAdmin($user, $isActivating, 'user');

            if ($isActivating && $user->isGeneralAdmin()) {
                if (! $user->generalAdminSignatures()->exists()) {
                    throw ValidationException::withMessages(['user' => 'Este Administrador General no tiene una firma registrada. Edita el usuario y sube una firma antes de activarlo.']);
                }

                $existingGeneralAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
                    ->where('is_active', true)
                    ->where('id', '!=', $user->id)
                    ->first();

                if ($existingGeneralAdmin && ! $request->boolean('confirm_replace')) {
                    throw ValidationException::withMessages([
                        'user' => 'Ya existe un Administrador General activo.',
                        'needs_general_admin_replace_confirmation' => 'true',
                        'current_general_admin_name' => $existingGeneralAdmin->name,
                        'activation_replace' => 'true',
                    ]);
                }

                if ($existingGeneralAdmin && $request->boolean('confirm_replace')) {
                    $this->deactivateGeneralAdmin($existingGeneralAdmin, $request);
                }

                $user->update(['is_active' => true, 'session_version' => ((int) $user->session_version) + 1]);

                $lastSignature = $user->generalAdminSignatures()->latest('id')->first();
                if ($lastSignature && ! $lastSignature->is_active) {
                    $lastSignature->update(['is_active' => true]);
                }

                app(AuditLogger::class)->record('user.activated', ['target_user_id' => $user->id], $user, $request, 'success');

                return back()->with('message', 'Usuario activado correctamente.');
            }

            $user->update(['is_active' => ! $user->is_active, 'session_version' => ((int) $user->session_version) + 1]);

            if (! $user->is_active && $user->isGeneralAdmin()) {
                $user->activeGeneralAdminSignature()?->update([
                    'is_active' => false,
                    'deactivated_by' => $request->user()->id,
                    'deactivated_at' => now(),
                    'deactivation_reason' => 'Usuario desactivado.',
                ]);
            }

            $message = $user->is_active ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.';
            app(AuditLogger::class)->record($user->is_active ? 'user.activated' : 'user.deactivated', [
                'target_user_id' => $user->id,
                'email' => $user->email,
            ], $user, $request, 'success');

            return back()->with('message', $message);

        }, $request, 'user.toggle');
    }

    public function signature(User $user): BinaryFileResponse
    {
        Gate::authorize('manage-users');
        $generalAdminSignature = $user->activeGeneralAdminSignature()->first()
            ?? $user->generalAdminSignatures()->latest('id')->first();

        abort_unless($generalAdminSignature && Storage::disk(config('paz-salvo.disk'))->exists($generalAdminSignature->signature_path), 404);

        return response()->file(Storage::disk(config('paz-salvo.disk'))->path($generalAdminSignature->signature_path));
    }

    public function unlockLoginAttempts(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        return $this->administrative(function () use ($request, $user, $audit) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage-users');
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
            ], $user, $request, 'success');

            return back()->with('message', 'Login desbloqueado correctamente.');

        }, $request, 'user.unlockLoginAttempts');
    }

    public function releaseActiveSession(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        return $this->administrative(function () use ($request, $user, $audit) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage-users');
            $deleted = DB::table('sessions')->where('user_id', $user->id)->delete();

            if ($deleted === 0) {
                return back()->with('message', 'El usuario no tiene sesión activa.');
            }

            $audit->record('user.session_released', [
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'sessions_deleted' => $deleted,
                'is_active' => $user->is_active,
            ], $user, $request, 'success');

            return back()->with('message', 'Sesión activa liberada correctamente.');

        }, $request, 'user.releaseActiveSession');
    }

    public function resetPassword(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        return $this->administrative(function () use ($request, $user, $audit) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('resetPassword', $user);

            $temporaryPassword = $this->temporaryPassword();

            $result = AdministrativeTransaction::run(function () use ($request, $user, $temporaryPassword) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $wasBlocked = (bool) $locked->is_login_blocked;
                $oldAttempts = (int) $locked->login_attempts;
                $newVersion = ((int) $locked->session_version) + 1;

                $locked->forceFill([
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'password_reset_at' => now(),
                    'password_reset_by' => $request->user()->id,
                    'password_changed_at' => null,
                    'login_attempts' => 0,
                    'is_login_blocked' => false,
                    'session_version' => $newVersion,
                    'remember_token' => Str::random(60),
                ])->save();

                $sessionsDeleted = DB::table('sessions')->where('user_id', $locked->id)->delete();

                return [
                    'user' => $locked,
                    'was_blocked' => $wasBlocked,
                    'old_attempts' => $oldAttempts,
                    'sessions_deleted' => $sessionsDeleted,
                ];
            });

            $target = $result['user'];
            $audit->record('user.password_reset', [
                'target_user_id' => $target->id,
                'target_email' => $target->email,
                'was_blocked' => $result['was_blocked'],
                'previous_login_attempts' => $result['old_attempts'],
                'sessions_revoked_count' => $result['sessions_deleted'],
            ], $target, $request, 'success');

            return back()->with('message', 'Contraseña restablecida correctamente.');

        }, $request, 'user.resetPassword');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        return $this->administrative(function () use ($request, $user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('manage-users');
            AdministrativeTransaction::protectLastAdmin($user, false, 'user');
            if ($user->generatedPazSalvos()->exists()) {
                throw ValidationException::withMessages(['user' => 'No se puede borrar un usuario con certificados generados.']);
            }
            if ($identity = $user->ssoIdentity()->first()) {
                if (! config('aaud-auth.automatic')) {
                    throw ValidationException::withMessages(['user' => 'Desvincule explícitamente la identidad SSO antes de borrar este usuario.']);
                }
                app(UserSsoIdentityManager::class)->unlink($user, $request->user(), $identity->id, $request);
            }

            $user->forceFill(['session_version' => ((int) $user->session_version) + 1])->save();
            $user->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            app(AuditLogger::class)->record('user.deleted', ['target_user_id' => $user->id], $user, $request, 'success');

            return back()->with('message', 'Usuario eliminado.');

        }, $request, 'user.destroy');
    }

    private function deactivateGeneralAdmin(User $user, Request $request): void
    {
        $user->activeGeneralAdminSignature()->update([
            'is_active' => false,
            'deactivated_by' => $request->user()->id,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Reemplazado por otro Administrador General.',
        ]);
        $user->update(['is_active' => false, 'session_version' => ((int) $user->session_version) + 1]);
        app(AuditLogger::class)->record('user.deactivated', ['target_user_id' => $user->id, 'reason' => 'general_admin_replacement'], $user, $request, 'success');
    }

    private array $preparedSignatures = [];

    private function prepareSignature(Request $request, User $user): string
    {
        $path = $request->file('general_admin_signature')->store('general-admin-signatures/'.$user->id, config('paz-salvo.disk'));
        if (! $path) {
            throw new \RuntimeException('No se pudo guardar la firma.');
        }
        $this->preparedSignatures[] = $path;

        return $path;
    }

    private function administrative(Closure $operation, Request $request, string $event): RedirectResponse
    {
        return $this->compensated(fn () => AdministrativeTransaction::run($operation), $request, $event);
    }

    private function compensated(Closure $operation, Request $request, string $event): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            foreach ($this->preparedSignatures as $path) {
                try {
                    if (! Storage::disk(config('paz-salvo.disk'))->delete($path)) {
                        throw new \RuntimeException('Signature cleanup failed');
                    }
                } catch (Throwable) {
                    Log::error('Administrative signature compensation failed.', ['path' => $path]);
                }
            }
            try {
                app(AuditLogger::class)->record($event.'.failed', ['exception_class' => $exception::class], null, $request, 'failed');
            } catch (Throwable) {
                Log::error('Administrative failure audit unavailable.', ['event' => $event]);
            }
            throw $exception;
        }
    }

    private function temporaryPassword(): string
    {
        $temporaryPassword = (string) config('security.temporary_user_password');

        if ($temporaryPassword === '') {
            throw ValidationException::withMessages([
                'temporary_password' => 'La contraseña temporal de usuarios no está configurada.',
            ]);
        }

        return $temporaryPassword;
    }
}
