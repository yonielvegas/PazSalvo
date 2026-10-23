<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdministrativeTransaction;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Historical access is never enrolled by a background scan. Explicit, audited opt-in only. */
final class InitializeAaudAuthAccess extends Command
{
    protected $signature = 'aaud-auth:initialize-access {user* : Explicit local user IDs} {--approved-by= : Administrator ID} {--authorize : Explicitly enroll and deliver these historical users}';

    protected $description = 'Previsualiza por defecto la inicialización explícita de accesos históricos.';

    public function handle(): int
    {
        $ids = array_unique($this->argument('user'));
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/\A[1-9][0-9]*\z/D', $id)) {
                $this->error('ID local inválido.');

                return self::FAILURE;
            }
        }
        $users = User::with('ssoIdentity')->findMany($ids);
        if ($users->count() !== count($ids) || $users->contains(fn ($user) => ! $user->ssoIdentity)) {
            $this->error('Todos los IDs deben existir y tener una vinculación SSO.');

            return self::FAILURE;
        }
        foreach ($users as $user) {
            $enrolled = DB::table('aaud_auth_access_targets')->where('application_user_id', (string) $user->id)->exists();
            $this->line('ID '.$user->id.': '.($enrolled ? 'ya registrado; no se duplica' : 'requiere consulta GET e inicialización'));
        }
        if (! $this->option('authorize')) {
            $this->comment('Solo previsualización. Sin HTTP ni escrituras.');

            return self::SUCCESS;
        }
        if (! config('aaud-auth.automatic') || ! config('aaud-auth.enabled')) {
            $this->error('La integración automática debe estar habilitada.');

            return self::FAILURE;
        }
        $actor = User::find($this->option('approved-by'));
        if (! $actor || ! Gate::forUser($actor)->allows('manage-users')) {
            $this->error('Se requiere un administrador autorizado explícito.');

            return self::FAILURE;
        }
        AdministrativeTransaction::run(function () use ($users, $actor) {
            foreach ($users as $user) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $user->ssoIdentity()->exists()) {
                    throw new \RuntimeException('La vinculación cambió. Repita la previsualización.');
                }
                if (! DB::table('aaud_auth_access_targets')->where('application_user_id', (string) $user->id)->exists()) {
                    AdministrativeTransaction::accessChanged($user, actor: $actor);
                    app(AuditLogger::class)->record('aaud_auth.access.historical_initialization_authorized',
                        ['originated_by' => $actor->id, 'application_user_id' => (string) $user->id], result: 'authorized');
                }
            }
        });
        $this->info('Inicialización autorizada registrada. Consulte el estado real en administración.');

        return self::SUCCESS;
    }
}
