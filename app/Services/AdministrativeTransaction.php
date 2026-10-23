<?php

namespace App\Services;

use App\Integrations\AaudAuth\Access\AccessDelivery;
use App\Integrations\AaudAuth\Access\AccessOutbox;
use App\Integrations\AaudAuth\Access\AccessSnapshotBuilder;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdministrativeTransaction
{
    private static int $depth = 0;

    private static array $accessChanges = [];

    /** Capture before a mutation; publish only the final state of the outer transaction. */
    public static function accessChanged(User $user, ?array $previous = null, ?User $actor = null): void
    {
        if (! config('aaud-auth.automatic')) {
            return;
        }
        $identity = $user->ssoIdentity()->first();
        if (! $identity && ! $previous && ! DB::table('aaud_auth_access_targets')->where('application_user_id', (string) $user->id)->exists()) {
            return; // Never enroll historical links by scanning or by an unrelated login update.
        }
        $actor ??= Auth::user();
        abort_unless($actor, 403, 'La modificación requiere un administrador identificado.');
        Gate::forUser($actor)->authorize('manage-users');
        $old = $previous ?? ($identity ? ['application_user_id' => (string) $user->id, 'issuer' => $identity->issuer, 'subject' => $identity->subject] : null);
        self::$accessChanges[(string) $user->id] ??= ['previous' => $old, 'actor' => $actor->id];
    }

    private static function persistAccessChanges(): void
    {
        $owners = array_keys(self::$accessChanges);
        foreach (self::$accessChanges as $owner => $change) {
            $user = User::find($owner);
            $snapshot = $user ? app(AccessSnapshotBuilder::class)->build($user) : null;
            app(AccessOutbox::class)->recordAdministrativeChange((string) $owner, $snapshot, $change['previous'], $change['actor']);
        }
        if ($owners !== []) {
            DB::afterCommit(function () use ($owners) {
                // Delivery failures cannot undo a committed local change. The Outbox remains recoverable.
                $message = 'Cambio local guardado, sincronización con AAUD AUTH pendiente.';
                try {
                    foreach ($owners as $owner) {
                        app(AccessDelivery::class)->deliver((string) $owner);
                    }
                    $message = app(AccessDelivery::class)->message($owners);
                } catch (Throwable $failure) {
                    // Even a storage outage during delivery must not enter filesystem rollback compensation
                    // after the local transaction has already committed.
                    Log::error('AAUD AUTH post-commit recovery required.', ['exception_class' => $failure::class]);
                }
                if (app()->bound('request') && request()->hasSession()) {
                    request()->session()->flash('sso_sync', $message);
                }
            });
        }
    }

    public static function run(Closure $operation, ?Request $request = null, ?string $event = null): mixed
    {
        if (self::$depth > 0) {
            return $operation();
        }
        $locked = function () use ($operation) {
            // Lock order: administration advisory lock, then role/user rows, then pivots/signatures.
            // Shared by all local administration paths; held until the outer transaction ends.
            DB::select('SELECT pg_advisory_xact_lock(17012026, 1)');

            self::$depth++;
            try {
                $result = $operation();
                self::persistAccessChanges();

                return $result;
            } finally {
                self::$accessChanges = [];
                self::$depth--;
            }
        };

        try {
            return DB::transaction($locked);
        } catch (Throwable $exception) {
            if ($event !== null) {
                try {
                    app(AuditLogger::class)->record($event.'.failed', ['exception_class' => $exception::class], null, $request, 'failed');
                } catch (Throwable) {
                    Log::error('Administrative failure audit unavailable.', ['event' => $event]);
                }
            }
            throw $exception;
        }
    }

    public static function protectLastAdmin(User $user, bool $retainsAdmin, string $field = 'role'): void
    {
        if (! $retainsAdmin && $user->is_active && $user->roles()->where('name', 'admin')->exists()
            && ! User::role('admin')->where('is_active', true)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages([$field => 'Debe permanecer al menos un administrador activo.']);
        }
    }
}
