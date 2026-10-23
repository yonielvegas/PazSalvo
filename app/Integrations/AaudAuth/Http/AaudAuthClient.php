<?php

namespace App\Integrations\AaudAuth\Http;

use App\Integrations\AaudAuth\Access\AccessSnapshot;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\InvalidAccessSnapshotException;
use App\Integrations\AaudAuth\Keycloak\TechnicalTokenProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use SensitiveParameter;

final class AaudAuthClient
{
    public function __construct(private readonly TechnicalTokenProvider $tokens) {}

    /**
     * The caller owns the complete snapshot, persistent version and operation UUID.
     * No database lookup or reconstruction is performed when this operation is retried.
     *
     * @param  list<array{code: string, name: string, active: bool}>  $roles
     */
    public function syncRoleCatalog(int $version, #[SensitiveParameter] string $operationId, #[SensitiveParameter] array $roles): RoleCatalogResult
    {
        $config = $this->configuration();
        if ($version < 1 || $version > 9007199254740991 || ! Str::isUuid($operationId)) {
            throw new AaudAuthRequestException('invalid_operation');
        }
        $this->validateRoles($roles);
        $operation = ['version' => $version, 'operation_id' => $operationId, 'roles' => $roles];
        try {
            $response = $this->request($config)->put($config['url'], $operation);
        } catch (ConnectionException $exception) {
            throw new AaudAuthRequestException($this->isTimeout($exception) ? 'timeout' : 'connection');
        }

        return $this->receipt($response, $operation);
    }

    /** Prepared operation only; one HTTP attempt, fixed application and no version/UUID generation. */
    public function syncUserAccess(#[SensitiveParameter] array $operation): UserAccessResult
    {
        $config = $this->configuration();
        $keys = array_keys($operation);
        sort($keys);
        if ($keys !== ['application_user_id', 'is_active', 'issuer', 'operation_id', 'roles', 'subject', 'version']
            || ! is_int($operation['version']) || $operation['version'] < 1 || $operation['version'] > 9007199254740991
            || ! is_string($operation['operation_id']) || ! Str::isUuid($operation['operation_id'])) {
            throw new AaudAuthRequestException('invalid_operation');
        }
        try {
            $snapshot = AccessSnapshot::fromArray(array_diff_key($operation, array_flip(['version', 'operation_id'])));
        } catch (InvalidAccessSnapshotException) {
            throw new AaudAuthRequestException('invalid_operation');
        }
        $payload = $operation;
        unset($payload['subject']);
        try {
            $response = $this->request($config, 'access.write')->put($config['base_url'].'/api/integrations/v1/applications/pazysalvo/users/'.rawurlencode($snapshot->subject).'/access', $payload);
        } catch (ConnectionException $exception) {
            throw new AaudAuthRequestException($this->isTimeout($exception) ? 'timeout' : 'connection');
        }
        if ($response->status() >= 400) {
            $this->reject($response);
        }
        if ($response->status() !== 200) {
            throw new AaudAuthRequestException('unexpected_response', $response->status());
        }
        try {
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }
        if (! is_array($body)) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }

        return UserAccessResult::fromReceipt($body, $snapshot, $operation['version'], $operation['operation_id']);
    }

    /** Fixed application, authenticated read. A 404 is an error, not proof of absence. */
    public function getUserAccess(AccessSnapshot $identity): RemoteUserAccess
    {
        $config = $this->configuration();
        // Revalidate against the current institutional issuer before obtaining a token.
        AccessSnapshot::fromArray($identity->payload());
        try {
            $response = $this->request($config, 'access.write')->get($config['base_url'].'/api/integrations/v1/applications/pazysalvo/users/'.rawurlencode($identity->subject).'/access', ['issuer' => $identity->issuer]);
        } catch (ConnectionException $exception) {
            throw new AaudAuthRequestException($this->isTimeout($exception) ? 'timeout' : 'connection');
        }
        if ($response->status() >= 400) {
            $this->reject($response);
        }
        if ($response->status() !== 200) {
            throw new AaudAuthRequestException('unexpected_response', $response->status());
        }
        try {
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }
        if (! is_array($body) || array_is_list($body) || array_key_exists('error', $body)
            || ! is_array($body['data'] ?? null) || array_is_list($body['data'])) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }

        return RemoteUserAccess::fromResponse($body['data'], $identity);
    }

    /** The receiver verifies LDAP federation/GUID provenance before returning these candidates. */
    public function searchDirectory(string $query, int $page = 1): array
    {
        $config = $this->configuration();
        $issuer = config('aaud-auth.issuer');
        $parts = is_string($issuer) && strlen($issuer) <= 255 && filter_var($issuer, FILTER_VALIDATE_URL) ? parse_url($issuer) : false;
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || str_ends_with($issuer, '/')
            || ! preg_match('~/realms/[A-Za-z0-9_-]+$~D', $parts['path'] ?? '')
            || (! in_array($parts['scheme'] ?? '', ['https'], true) && ! (($parts['scheme'] ?? '') === 'http'
                && in_array(config('app.env'), ['local', 'testing'], true)
                && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true)))) {
            throw new AaudAuthRequestException('configuration');
        }
        if (trim($query) !== $query || ! mb_check_encoding($query, 'UTF-8') || mb_strlen($query) < 3 || mb_strlen($query) > 80
            || preg_match('/[\x00-\x1f\x7f]/u', $query) || $page < 1 || $page > 10) {
            throw new AaudAuthRequestException('invalid_request');
        }
        try {
            $response = $this->request($config)->get($config['base_url'].'/api/integrations/v1/directory/users', [
                'q' => $query, 'page' => $page, 'limit' => 20,
            ]);
        } catch (ConnectionException $exception) {
            throw new AaudAuthRequestException($this->isTimeout($exception) ? 'timeout' : 'connection');
        }
        if ($response->status() >= 400) {
            $this->reject($response);
        }
        if ($response->status() !== 200) {
            throw new AaudAuthRequestException('unexpected_response', $response->status());
        }
        try {
            $body = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }
        if (! is_array($body) || isset($body['error']) || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])
            || count($body['data']) > 20 || ($body['page'] ?? null) !== $page || ($body['limit'] ?? null) !== 20
            || ! is_bool($body['has_more'] ?? null)) {
            throw new AaudAuthRequestException('invalid_response', 200);
        }
        $seen = [];
        $identities = [];
        foreach ($body['data'] as $row) {
            if (! is_array($row) || ($row['issuer'] ?? null) !== $issuer || ! is_string($row['subject'] ?? null)
                || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/D', $row['subject']) || isset($seen[$row['subject']])
                || ! is_bool($row['enabled'] ?? null) || ! is_bool($row['name_is_fallback'] ?? null)) {
                throw new AaudAuthRequestException('invalid_response', 200);
            }
            foreach (['name', 'username'] as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '' || ! mb_check_encoding($row[$field], 'UTF-8')
                    || mb_strlen($row[$field]) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $row[$field])) {
                    throw new AaudAuthRequestException('invalid_response', 200);
                }
            }
            if (! array_key_exists('email', $row) || ($row['email'] !== null && (! is_string($row['email'])
                || ! mb_check_encoding($row['email'], 'UTF-8') || mb_strlen($row['email']) > 255
                || preg_match('/[\x00-\x1f\x7f]/u', $row['email'])))) {
                throw new AaudAuthRequestException('invalid_response', 200);
            }
            $seen[$row['subject']] = true;
            $identities[] = array_intersect_key($row, array_flip(['issuer', 'subject', 'username', 'name', 'name_is_fallback', 'email', 'enabled']));
        }

        return ['data' => $identities, 'page' => $page, 'limit' => 20, 'has_more' => $body['has_more']];
    }

    private function request(array $config, ?string $requiredScope = null): PendingRequest
    {
        $token = $this->tokens->getToken($requiredScope);

        return Http::asJson()->acceptJson()->withToken($token->accessToken())
            ->connectTimeout($config['connect'])->timeout($config['timeout'])
            ->withOptions(['verify' => $config['verify'], 'allow_redirects' => false, 'http_errors' => false]);
    }

    private function configuration(): array
    {
        if (config('aaud-auth.enabled') !== true) {
            throw new AaudAuthRequestException('disabled');
        }
        $url = config('aaud-auth.api_url');
        $parts = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
        $localHttp = is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array(config('app.env'), ['local', 'testing'], true)
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        $integer = fn ($value) => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        $connect = $integer(config('aaud-auth.connect_timeout'));
        $timeout = $integer(config('aaud-auth.timeout'));
        $ca = config('aaud-auth.ca_bundle');
        if (! is_array($parts) || (! $localHttp && ($parts['scheme'] ?? '') !== 'https')
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $connect === false || $connect < 1 || $timeout === false || $timeout < $connect
            || config('aaud-auth.verify_tls') !== true
            || ($ca !== null && $ca !== '' && (! is_string($ca) || str_contains($ca, '://') || ! is_file($ca) || ! is_readable($ca)))) {
            throw new AaudAuthRequestException('configuration');
        }

        return ['base_url' => rtrim($url, '/'), 'url' => rtrim($url, '/').'/api/integrations/v1/applications/pazysalvo/roles',
            'connect' => $connect, 'timeout' => $timeout, 'verify' => $ca ?: true];
    }

    private function validateRoles(#[SensitiveParameter] array $roles): void
    {
        if (! array_is_list($roles) || count($roles) < 1 || count($roles) > 1000) {
            throw new AaudAuthRequestException('invalid_operation');
        }
        $seen = [];
        foreach ($roles as $role) {
            $keys = is_array($role) ? array_keys($role) : [];
            sort($keys);
            if ($keys !== ['active', 'code', 'name'] || ! is_string($role['code'])
                || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $role['code']) || isset($seen[$role['code']])
                || ! is_string($role['name']) || ! mb_check_encoding($role['name'], 'UTF-8')
                || mb_strlen($role['name'], 'UTF-8') > 255 || preg_match('/[^\p{Z}\s]/u', $role['name']) !== 1
                || preg_match('/[\x00-\x1f\x7f]/u', $role['name']) || ! is_bool($role['active'])) {
                throw new AaudAuthRequestException('invalid_operation');
            }
            $seen[$role['code']] = true;
        }
    }

    private function receipt(#[SensitiveParameter] Response $response, #[SensitiveParameter] array $operation): RoleCatalogResult
    {
        $status = $response->status();
        if ($status >= 400) {
            $this->reject($response);
        }
        if ($status !== 200) {
            throw new AaudAuthRequestException('unexpected_response', $status);
        }
        try {
            $body = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AaudAuthRequestException('invalid_response', $status);
        }
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $kind = is_array($body) ? ($body['status'] ?? null) : null;
        if (! is_array($body) || isset($body['error']) || ! in_array($kind, ['applied', 'duplicate'], true) || ! is_array($data)
            || ($data['application_code'] ?? null) !== 'pazysalvo' || ($data['version'] ?? null) !== $operation['version']
            || ($data['operation_id'] ?? null) !== strtolower($operation['operation_id'])
            || ! is_int($body['current_version'] ?? null) || $body['current_version'] < $operation['version']
            || $body['current_version'] > 9007199254740991 || ! is_bool($body['historical'] ?? null)
            || $body['historical'] !== ($body['current_version'] > $operation['version'])
            || ($kind === 'applied' && $body['historical'])
            || ($kind === 'duplicate' && ($body['current_state']['catalog_version'] ?? null) !== $body['current_version'])
            || ! $this->matchesSnapshot($data['roles'] ?? null, $operation['roles'])) {
            throw new AaudAuthRequestException('invalid_response', $status);
        }

        // Carry the validated snapshot so durable confirmation can bind the receipt to its content.
        $roles = array_map(fn ($role) => ['code' => $role['code'], 'name' => trim($role['name']),
            'active' => $role['active'], 'description' => null], $operation['roles']);
        usort($roles, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return new RoleCatalogResult($kind, $operation['version'], $operation['operation_id'], $body['current_version'], $body['historical'], $roles);
    }

    private function matchesSnapshot(#[SensitiveParameter] mixed $echoed, #[SensitiveParameter] array $sent): bool
    {
        if (! is_array($echoed) || ! array_is_list($echoed) || count($echoed) !== count($sent)) {
            return false;
        }
        $received = [];
        foreach ($echoed as $role) {
            if (! is_array($role) || array_diff(array_keys($role), ['code', 'name', 'active', 'description'])
                || ! is_string($role['code'] ?? null) || ! is_string($role['name'] ?? null)
                || ! is_bool($role['active'] ?? null) || ($role['description'] ?? null) !== null) {
                return false;
            }
            $received[] = ['code' => $role['code'], 'name' => $role['name'], 'active' => $role['active']];
        }
        // Compare with the receiver's documented normalization without altering the outgoing body.
        $expected = array_map(fn ($role) => ['code' => $role['code'], 'name' => trim($role['name']), 'active' => $role['active']], $sent);
        $sort = fn ($a, $b) => strcmp($a['code'], $b['code']);
        usort($received, $sort);
        usort($expected, $sort);

        return $received === $expected;
    }

    private function reject(#[SensitiveParameter] Response $response): never
    {
        $status = $response->status();
        $candidate = $response->json('error.code');
        $known = ['invalid_service_token', 'client_not_authorized', 'application_mismatch', 'stale_version',
            'operation_conflict', 'operation_or_identity_conflict', 'validation_failed', 'catalog_role_invalid_or_duplicate',
            'catalog_role_name_invalid', 'rate_limited', 'application_not_available', 'integration_temporarily_disabled',
            'authorization_provider_unavailable', 'integration_authentication_pending', 'storage_unavailable',
            'institutional_identity_not_found', 'application_access_not_found', 'institutional_identity_disabled',
            'institutional_identity_unverifiable', 'institutional_identity_conflict', 'application_identity_conflict',
            'roles_invalid_or_inactive', 'role_code_invalid', 'subject_invalid', 'revocation_requires_empty_roles',
            'catalog_not_confirmed', 'directory_unavailable', 'directory_configuration_pending',
            'service_unavailable', 'endpoint_not_found', 'method_not_allowed', 'request_rejected'];
        $code = is_string($candidate) && in_array($candidate, $known, true) ? $candidate : null;
        $reason = match (true) {
            $status === 401 => 'unauthorized', $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 && $code === 'stale_version' => 'version_conflict',
            $status === 409 && $code === 'operation_conflict' => 'idempotency_conflict',
            $status === 409 => 'conflict', $status === 422 || $status === 400 => 'invalid_request',
            $status === 429 => 'rate_limited', $status >= 500 => 'temporary', default => 'unexpected_response',
        };
        $after = $response->header('Retry-After');
        $seconds = is_string($after) && ctype_digit($after) ? filter_var($after, FILTER_VALIDATE_INT) : false;

        throw new AaudAuthRequestException($reason, $status, $code, $seconds !== false ? $seconds : null);
    }

    private function isTimeout(#[SensitiveParameter] ConnectionException $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (method_exists($cause, 'getHandlerContext') && ($cause->getHandlerContext()['errno'] ?? null) === 28) {
                return true;
            }
        }

        return preg_match('/timed?\s*out|timeout/i', $exception->getMessage()) === 1;
    }

    public function __debugInfo(): array
    {
        return ['client' => 'AAUD AUTH'];
    }

    public function __serialize(): array
    {
        throw new LogicException('No se permite serializar clientes técnicos.');
    }
}
