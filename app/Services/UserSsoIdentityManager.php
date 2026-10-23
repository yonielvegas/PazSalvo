<?php

namespace App\Services;

use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Models\User;
use App\Models\UserSsoIdentity;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UserSsoIdentityManager
{
    public function __construct(private readonly AaudAuthClient $client, private readonly AuditLogger $audit) {}

    public function link(User $target, User $actor, array $selection, Request $request): UserSsoIdentity
    {
        Gate::forUser($actor)->authorize('manage-users');

        return $this->audited('linked', $target, $selection, $request, function () use ($target, $actor, $selection, $request) {
            $verified = $this->verifySelection($selection);

            return $this->transaction($target, $actor, fn (User $locked) => $this->persistLink($locked, $actor, $verified, $request));
        });
    }

    /** Verify remotely before the transaction; the callback only creates the local user. */
    public function createAndLink(User $actor, array $selection, Request $request, Closure $create): User
    {
        Gate::forUser($actor)->authorize('manage-users');

        return $this->audited('linked', null, $selection, $request, function () use ($actor, $selection, $request, $create) {
            $verified = $this->verifySelection($selection);

            return DB::transaction(fn () => AdministrativeTransaction::run(function () use ($actor, $verified, $request, $create) {
                Gate::forUser(User::findOrFail($actor->id))->authorize('manage-users');
                $this->assertIdentityAvailable($verified);
                $profile = $this->verifiedProfile($verified);
                $user = $create($profile);
                $this->persistLink($user, $actor, $verified, $request);

                return $user;
            }));
        });
    }

    /** Keys identify verified fields; missing directory values are never inferred from users. */
    public function verifiedProfile(array $verified): array
    {
        $profile = ['username' => User::normalizeUsername($verified['username'])];
        if (! $verified['name_is_fallback']) {
            $profile['name'] = trim($verified['name']);
        }
        $email = is_string($verified['email']) ? mb_strtolower(trim($verified['email']), 'UTF-8') : null;
        if ($email && Validator::make(['email' => $email], ['email' => ['email', 'max:255']])->passes()) {
            $profile['email'] = $email;
        }
        if (Validator::make($profile, ['username' => ['required', 'string', 'max:255', 'regex:/\A[^\x00-\x1f\x7f]+\z/uD'],
            'name' => ['sometimes', 'required', 'string', 'max:255']])->fails()) {
            throw new AaudAuthRequestException('invalid_response');
        }

        return $profile;
    }

    /** One local transaction for pending profile, role and SSO changes. No HTTP in the transaction. */
    public function updateWithIdentity(User $target, User $actor, array $change, Request $request, Closure $update): User
    {
        Gate::forUser($actor)->authorize('manage-users');
        $action = $change['action'];
        if (! in_array($action, ['keep', 'link', 'unlink'], true)) {
            throw ValidationException::withMessages(['identity' => 'Operación de identidad inválida.']);
        }

        $work = function () use ($target, $actor, $change, $request, $update, $action) {
            $verified = $action === 'link' ? $this->verifySelection($change['selection']) : null;

            return $this->transaction($target, $actor, function (User $locked) use ($actor, $change, $request, $update, $action, $verified) {
                $identity = $locked->ssoIdentity()->lockForUpdate()->first();
                if ($change['check_expected'] && ($identity?->id !== $change['expected_id'])) {
                    throw ValidationException::withMessages(['identity' => 'La vinculación cambió. Actualice el formulario antes de guardar.']);
                }
                if ($action === 'unlink' && (! $identity || ! $change['confirmed'] || ! $change['check_expected'] || $identity->id !== $change['expected_id'])) {
                    throw ValidationException::withMessages(['identity' => 'Confirme explícitamente la desvinculación antes de guardar.']);
                }
                if ($verified && $identity && ($identity->issuer !== $verified['issuer'] || $identity->subject !== $verified['subject'])) {
                    throw ValidationException::withMessages(['identity' => 'Desvincule y guarde la identidad actual antes de seleccionar otra.']);
                }
                if ($verified && ! $identity) {
                    $this->assertIdentityAvailable($verified);
                }
                $profile = $verified ? $this->verifiedProfile($verified) : array_intersect_key($identity?->verified_profile ?? [], array_flip(['username', 'name', 'email']));
                if ($action === 'unlink') {
                    $profile = ['username' => $locked->username]; // Unlinking must preserve the saved username.
                }
                $this->assertProfileAvailable($profile, $locked);
                $before = $locked->only(['username', 'name', 'email']);
                $update($locked, $profile);
                if ($action === 'link') {
                    if ($identity) {
                        $identity->update(['verified_profile' => $profile]);
                        $this->auditProfile($locked, $before, $profile, $request);
                    } else {
                        $this->persistLink($locked, $actor, $verified, $request, $before);
                    }
                } elseif ($action === 'unlink') {
                    $this->unlink($locked, $actor, $identity->id, $request);
                }

                return $locked;
            });
        };

        return $action === 'keep' ? $work() : $this->audited($action === 'unlink' ? 'unlinked' : 'linked', $target, $change['selection'] ?? [], $request, $work);
    }

    private function assertProfileAvailable(array $profile, ?User $user = null): void
    {
        foreach (['username', 'email'] as $field) {
            if (isset($profile[$field]) && User::whereRaw('LOWER('.$field.') = LOWER(?)', [$profile[$field]])
                ->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists()) {
                throw ValidationException::withMessages([$field => 'El '.$field.' institucional ya pertenece a otro usuario local.']);
            }
        }
    }

    private function auditProfile(User $user, array $before, array $profile, Request $request): void
    {
        $changed = array_values(array_filter(array_keys($profile), fn ($field) => ($before[$field] ?? null) !== $user->getAttribute($field)));
        $this->audit->record('user.sso.profile.applied', ['actor_user_id' => $request->user()->id, 'target_user_id' => $user->id,
            'verified_fields' => array_keys($profile), 'replaced_fields' => $changed], $user, $request, 'success');
    }

    private function verifySelection(array $selection): array
    {
        if ($selection['issuer'] !== config('aaud-auth.issuer')) {
            throw ValidationException::withMessages(['identity' => 'El emisor seleccionado no es el directorio configurado.']);
        }
        $results = $this->client->searchDirectory($selection['query'], $selection['page']);
        $verified = collect($results['data'])->first(fn ($identity) => $identity['issuer'] === $selection['issuer'] && $identity['subject'] === $selection['subject']);
        if (! $verified || ! $verified['enabled']) {
            throw ValidationException::withMessages(['identity' => 'La identidad ya no existe en los resultados verificados o está deshabilitada. Repita la búsqueda.']);
        }

        return $verified;
    }

    private function assertIdentityAvailable(array $verified): void
    {
        if (UserSsoIdentity::where('issuer', $verified['issuer'])->where('subject', $verified['subject'])->exists()) {
            throw ValidationException::withMessages(['identity' => 'La identidad seleccionada ya pertenece a otro usuario local.']);
        }
    }

    private function persistLink(User $user, User $actor, array $verified, Request $request, ?array $previousProfile = null): UserSsoIdentity
    {
        if ($user->ssoIdentity()->exists()) {
            throw ValidationException::withMessages(['identity' => 'El usuario ya tiene una identidad vinculada. Desvincúlela explícitamente antes de sustituirla.']);
        }
        $this->assertIdentityAvailable($verified);
        $profile = $this->verifiedProfile($verified);
        $this->assertProfileAvailable($profile, $user);
        $before = $previousProfile ?? $user->only(['username', 'name', 'email']);
        $user->fill($profile);
        if ($user->isDirty()) {
            $user->session_version = (int) $user->session_version + 1;
            $user->save();
        }
        $identity = UserSsoIdentity::create(['user_id' => $user->id, 'issuer' => $verified['issuer'],
            'subject' => $verified['subject'], 'created_by' => $actor->id, 'verified_profile' => $profile]);
        AdministrativeTransaction::accessChanged($user, actor: $actor);
        $this->auditProfile($user, $before, $profile, $request);
        $this->audit->record('user.sso.linked', ['actor_user_id' => $actor->id, 'target_user_id' => $user->id,
            'issuer' => $identity->issuer, 'subject' => $identity->subject, 'identity_id' => $identity->id], $user, $request, 'success');

        return $identity;
    }

    public function unlink(User $target, User $actor, int $identityId, Request $request): void
    {
        Gate::forUser($actor)->authorize('manage-users');
        $this->audited('unlinked', $target, ['identity_id' => $identityId], $request, function () use ($target, $actor, $identityId, $request) {
            $this->transaction($target, $actor, function (User $locked) use ($identityId, $request) {
                $identity = $locked->ssoIdentity()->lockForUpdate()->first();
                if (! $identity || $identity->id !== $identityId) {
                    throw ValidationException::withMessages(['identity' => 'La vinculación cambió. Actualice la vista antes de confirmar.']);
                }
                $metadata = ['actor_user_id' => $request->user()->id, 'target_user_id' => $locked->id, 'issuer' => $identity->issuer,
                    'subject' => $identity->subject, 'identity_id' => $identity->id];
                AdministrativeTransaction::accessChanged($locked, ['application_user_id' => (string) $locked->id, 'issuer' => $identity->issuer, 'subject' => $identity->subject], $request->user());
                $identity->delete();
                $this->audit->record('user.sso.unlinked', $metadata, $locked, $request, 'success');
            });
        });
    }

    private function transaction(User $target, User $actor, Closure $work): mixed
    {
        // Savepoint keeps this unit atomic even when the caller catches errors in an outer transaction.
        return DB::transaction(fn () => AdministrativeTransaction::run(function () use ($target, $actor, $work) {
            // Existing order: administration advisory lock -> user row -> identity row/index.
            $currentActor = User::findOrFail($actor->id);
            Gate::forUser($currentActor)->authorize('manage-users');
            $locked = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            return $work($locked);
        }));
    }

    private function audited(string $action, ?User $target, array $selection, Request $request, Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable $failure) {
            try {
                $this->audit->record('user.sso.'.$action.'.failed', ['actor_user_id' => $request->user()?->id, 'target_user_id' => $target?->id,
                    ...array_intersect_key($selection, array_flip(['issuer', 'subject', 'identity_id'])),
                    'exception_class' => $failure::class], $target, $request, 'failed');
            } catch (Throwable) {
                Log::error('SSO identity failure audit unavailable.');
            }
            if ($failure instanceof QueryException && $failure->getCode() === '23505') {
                throw ValidationException::withMessages(['identity' => 'El usuario o la identidad ya fueron vinculados por otra operación. Actualice la vista.']);
            }
            throw $failure;
        }
    }
}
