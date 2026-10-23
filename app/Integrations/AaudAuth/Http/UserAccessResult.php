<?php

namespace App\Integrations\AaudAuth\Http;

use App\Integrations\AaudAuth\Access\AccessSnapshot;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use Illuminate\Support\Str;

/** Constructed only from a validated receipt. Evidence at response time, not durable sync state. */
final readonly class UserAccessResult
{
    private function __construct(
        public string $status,
        public AccessSnapshot $snapshot,
        public int $version,
        public string $operationId,
        public string $confirmedAt,
        public int $currentVersion,
        public bool $historical,
        public ?array $currentState,
    ) {}

    public static function fromReceipt(#[\SensitiveParameter] array $body, AccessSnapshot $snapshot, int $version, string $operationId): self
    {
        $invalid = fn () => new AaudAuthRequestException('invalid_response', 200);
        $data = $body['data'] ?? null;
        $status = $body['status'] ?? null;
        if ($version < 1 || $version > 9007199254740991 || ! Str::isUuid($operationId)
            || array_key_exists('error', $body) || ! in_array($status, ['applied', 'duplicate'], true) || ! is_array($data)
            || ($data['application_code'] ?? null) !== 'pazysalvo'
            || ($data['version'] ?? null) !== $version || ($data['operation_id'] ?? null) !== strtolower($operationId)
            || ! is_string($data['confirmed_at'] ?? null) || ! self::validDate($data['confirmed_at'])
            || ! is_int($body['current_version'] ?? null) || $body['current_version'] < $version
            || $body['current_version'] > 9007199254740991 || ! is_bool($body['historical'] ?? null)) {
            throw $invalid();
        }
        foreach ($snapshot->payload() as $key => $value) {
            if (! array_key_exists($key, $data) || $data[$key] !== $value) {
                throw $invalid();
            }
        }
        $current = null;
        if ($status === 'applied') {
            if ($body['historical'] || $body['current_version'] !== $version || array_key_exists('current_state', $body)) {
                throw $invalid();
            }
        } else {
            $state = $body['current_state'] ?? null;
            if (! is_array($state) || ($state['version'] ?? null) !== $body['current_version']
                || ! is_bool($state['is_active'] ?? null) || ! self::validCurrentRoles($state['roles'] ?? null)
                || ($state['is_active'] && $state['roles'] === [])
                || ! is_int($state['catalog_version'] ?? null) || $state['catalog_version'] < 0
                || $state['catalog_version'] > 9007199254740991) {
                throw $invalid();
            }
            // Catalog reconciliation can change roles/active without changing this identity's version.
            $changed = $body['current_version'] > $version || $state['is_active'] !== $snapshot->isActive || $state['roles'] !== $snapshot->roles;
            if ($body['historical'] !== $changed) {
                throw $invalid();
            }
            $current = array_intersect_key($state, array_flip(['version', 'is_active', 'roles', 'catalog_version']));
        }

        return new self($status, $snapshot, $version, strtolower($operationId), $data['confirmed_at'], $body['current_version'], $body['historical'], $current);
    }

    public function wasApplied(): bool
    {
        return $this->status === 'applied';
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    /** A historical acknowledgement never proves the current replica matches the desired state. */
    public function confirmsCurrentAccess(): bool
    {
        return ! $this->historical && $this->currentVersion === $this->version;
    }

    private static function validDate(#[\SensitiveParameter] string $date): bool
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/D', $date)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private static function validCurrentRoles(#[\SensitiveParameter] mixed $roles): bool
    {
        if (! is_array($roles) || ! array_is_list($roles) || count($roles) > 50) {
            return false;
        }
        $previous = null;
        foreach ($roles as $role) {
            if (! is_string($role) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,79}\z/D', $role)
                || ($previous !== null && strcmp($previous, $role) >= 0)) {
                return false;
            }
            $previous = $role;
        }

        return true;
    }
}
