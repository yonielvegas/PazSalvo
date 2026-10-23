<?php

namespace App\Integrations\AaudAuth\Roles;

use Carbon\CarbonImmutable;

/** A detached, immutable snapshot; no token or HTTP dependency. */
final readonly class CatalogOperation
{
    public int $id;

    public int $version;

    public string $operationId;

    public string $payloadHash;

    public string $status;

    public int $attempts;

    public bool $hasUncertainAttempt;

    public ?string $claimId;

    public ?CarbonImmutable $leaseExpiresAt;

    public ?CarbonImmutable $nextAttemptAt;

    public ?string $lastErrorCode;

    private array $snapshot;

    public function __construct(object $row)
    {
        $this->id = (int) $row->id;
        $this->version = (int) $row->version;
        $this->operationId = $row->operation_id;
        $this->payloadHash = $row->payload_hash;
        $this->status = $row->status;
        $this->attempts = (int) $row->attempts;
        $this->hasUncertainAttempt = (bool) $row->has_uncertain_attempt;
        $this->claimId = $row->claim_id;
        $this->leaseExpiresAt = $row->lease_expires_at ? CarbonImmutable::parse($row->lease_expires_at) : null;
        $this->nextAttemptAt = $row->next_attempt_at ? CarbonImmutable::parse($row->next_attempt_at) : null;
        $this->lastErrorCode = $row->last_error_code;
        $payload = json_decode($row->payload, true, 64, JSON_THROW_ON_ERROR);
        // JSONB does not preserve object key order. Reconstitute the same request shape on every read.
        $this->snapshot = ['version' => $this->version, 'operation_id' => $this->operationId,
            'roles' => array_map(fn ($role) => ['code' => $role['code'], 'name' => $role['name'], 'active' => $role['active']], $payload['roles'])];
    }

    public function payload(): array
    {
        return $this->snapshot;
    }
}
