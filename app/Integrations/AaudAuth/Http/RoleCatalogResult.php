<?php

namespace App\Integrations\AaudAuth\Http;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RoleCatalogResult
{
    public function __construct(
        public string $status,
        public int $version,
        public string $operationId,
        public int $currentVersion,
        public bool $historical,
        public ?array $roles = null,
    ) {
        if (! in_array($status, ['applied', 'duplicate'], true) || ! Str::isUuid($operationId)
            || $version < 1 || $currentVersion < $version || $currentVersion > 9007199254740991
            || $historical !== ($currentVersion > $version) || ($status === 'applied' && $historical)) {
            throw new InvalidArgumentException('Recibo de catálogo inconsistente.');
        }
    }

    /** Evidence at response time only; this is not a persisted synchronization status. */
    public function confirmsCurrentCatalog(): bool
    {
        return ! $this->historical && $this->currentVersion === $this->version;
    }
}
