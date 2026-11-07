<?php

namespace App\Integrations\AaudOauth\Roles;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Local incident recovery only: fixed SELECT, fixed development container, no credentials in argv. */
class LocalCatalogReader
{
    public function read(): array
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            throw new RuntimeException('La recuperación requiere un entorno local explícito.');
        }
        $sql = <<<'SQL'
BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;
SELECT json_build_object('database',current_database(),'read_only',current_setting('transaction_read_only'),'observed_at',extract(epoch from clock_timestamp())::bigint,
'application_code',a.code,'application_active',a.active,'version',a.catalog_version,
'roles',COALESCE((SELECT json_agg(json_build_object('code',r.code,'name',r.name,'active',r.active,'deleted_at_source',r.deleted_at_source,
'assignments',(SELECT count(*) FROM user_application_roles g WHERE g.application_id=a.id AND g.role_id=r.id)) ORDER BY r.code COLLATE "C") FROM application_roles r WHERE r.application_id=a.id),'[]'::json),
'client_verified',EXISTS(SELECT 1 FROM integration_clients c WHERE c.application_id=a.id AND c.client_id='pazysalvo-sync' AND c.active
AND c.service_subject='a5c9e457-8bf1-4255-8a45-410c1c917254' AND c.scopes ? 'roles.write' AND c.scopes ? 'access.write'))
FROM applications a WHERE a.code='pazysalvo';
ROLLBACK;
SQL;
        $result = Process::timeout(10)->input($sql)->run(['docker', 'exec', '-i', 'aaud-auth-postgres-1', 'sh', '-c',
            'PGOPTIONS="-c default_transaction_read_only=on -c statement_timeout=5000" psql -X -A -t -q -U "$POSTGRES_USER" -d aaud -v ON_ERROR_STOP=1']);
        if (! $result->successful()) {
            throw new RuntimeException('No se pudo verificar aaud mediante PostgreSQL READ ONLY. No se debe continuar.');
        }
        try {
            $observation = json_decode(trim($result->output()), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('La observación remota no contiene JSON verificable.');
        }
        $this->validate($observation);

        return $observation;
    }

    public function validate(mixed $observation): void
    {
        if (! is_array($observation) || ($observation['database'] ?? null) !== 'aaud' || ($observation['read_only'] ?? null) !== 'on'
            || ($observation['application_code'] ?? null) !== 'pazysalvo' || ($observation['application_active'] ?? null) !== true
            || ($observation['client_verified'] ?? null) !== true || ! is_int($observation['version'] ?? null)
            || $observation['version'] < 0 || $observation['version'] >= 9007199254740991
            || ! is_int($observation['observed_at'] ?? null) || abs(time() - $observation['observed_at']) > 30
            || ! is_array($observation['roles'] ?? null) || ! array_is_list($observation['roles']) || count($observation['roles']) > 1000) {
            throw new RuntimeException('La evidencia remota no es actual, activa o verificable.');
        }
        $seen = [];
        foreach ($observation['roles'] as $role) {
            if (! is_array($role) || ! is_string($role['code'] ?? null) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $role['code'])
                || isset($seen[$role['code']]) || ! is_string($role['name'] ?? null) || trim($role['name']) === ''
                || mb_strlen($role['name']) > 255 || ! mb_check_encoding($role['name'], 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/u', $role['name'])
                || ! is_bool($role['active'] ?? null) || ! is_bool($role['deleted_at_source'] ?? null)
                || ! is_int($role['assignments'] ?? null) || $role['assignments'] < 0 || ($role['deleted_at_source'] && $role['active'])) {
                throw new RuntimeException('El catálogo observado contiene roles inconsistentes.');
            }
            $seen[$role['code']] = true;
        }
    }
}
