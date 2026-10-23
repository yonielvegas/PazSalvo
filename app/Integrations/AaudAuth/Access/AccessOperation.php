<?php

namespace App\Integrations\AaudAuth\Access;

use Carbon\CarbonImmutable;

final readonly class AccessOperation
{
    public int $id;

    public string $status;

    public int $attempts;

    public ?string $claimId;

    public ?CarbonImmutable $leaseExpiresAt;

    public string $payloadHash;

    public ?array $receipt;

    private array $snapshot;

    public function __construct(object $row)
    {
        $this->id = (int) $row->id;
        $this->status = $row->status;
        $this->attempts = (int) $row->attempts;
        $this->claimId = $row->claim_id;
        $this->leaseExpiresAt = $row->lease_expires_at ? CarbonImmutable::parse($row->lease_expires_at) : null;
        $this->payloadHash = $row->payload_hash;
        $this->receipt = $row->receipt ? json_decode($row->receipt, true, flags: JSON_THROW_ON_ERROR) : null;
        $p = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->snapshot = AccessSnapshot::fromArray(array_diff_key($p, array_flip(['version', 'operation_id'])))->payload()
            + ['version' => (int) $row->version, 'operation_id' => $row->operation_id];
    }

    public function payload(): array
    {
        return $this->snapshot;
    }
}
