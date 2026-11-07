<?php

namespace App\Console\Commands;

use App\Integrations\AaudOauth\Access\AccessDelivery;
use App\Integrations\AaudOauth\Roles\CatalogRecovery;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

final class RecoverAaudOauthCatalog extends Command
{
    protected $signature = 'aaud-oauth:recover-catalog {--expect-version= : Remote version to verify again} {--execute : Authorize this exact recovery and HTTP delivery} {--plan= : Approved preview SHA-256} {--approved-by= : Explicit local administrator ID} {--resume-target= : Only resume an existing access after catalog recovery}';

    protected $description = 'Previsualiza por defecto la recuperación local del catálogo mediante PostgreSQL READ ONLY.';

    public function handle(CatalogRecovery $recovery): int
    {
        try {
            if (! in_array(config('app.env'), ['local', 'testing'], true)) {
                throw new \RuntimeException('Este procedimiento está limitado al entorno local.');
            }
            $version = filter_var($this->option('expect-version'), FILTER_VALIDATE_INT);
            if ($version === false || $version < 0) {
                throw new \RuntimeException('Indique explícitamente --expect-version.');
            }
            if (! $this->option('execute')) {
                $plan = $recovery->plan($version);
                unset($plan['observation']);
                $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $this->comment('Solo lectura. Sin observaciones locales, operaciones ni peticiones HTTP.');

                return self::SUCCESS;
            }
            $hash = $this->option('plan');
            if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/D', $hash) || ! $this->option('approved-by')) {
                throw new \RuntimeException('Se requiere SHA-256 aprobado y administrador explícito antes de efectuar escrituras o PUT.');
            }
            $actor = User::findOrFail($this->option('approved-by'));
            if ($owner = $this->option('resume-target')) {
                $plan = $recovery->plan($version);
                if (! hash_equals($hash, $plan['approval_hash']) || $plan['candidate_version'] !== null) {
                    throw new \RuntimeException('La evidencia del catálogo cambió o no coincide con el local.');
                }
                $recovery->resumeTarget((string) $owner, $actor, 'catalog-recovery:'.$hash);
                app(AccessDelivery::class)->deliver((string) $owner);
                $this->info('Reanudación registrada. Consulte el estado real del acceso.');
            } else {
                $this->line(json_encode($recovery->execute($version, $hash, $actor), JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Only application-owned, sanitized diagnostics; never transport exception dumps/SQL/credentials.
            $message = $error instanceof \RuntimeException && ! $error instanceof QueryException ? $error->getMessage() : 'No se pudo completar la recuperación. No asuma sincronización.';
            $this->error($message);

            return self::FAILURE;
        }
    }
}
