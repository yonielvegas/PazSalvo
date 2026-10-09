<?php

namespace App\Services;

use App\Models\PazSalvo;
use Illuminate\Database\Eloquent\Builder;

class PazSalvoStatistics
{
    /** Cancelled certificates count toward total, but never toward valid or expired. */
    public function count(Builder $query): array
    {
        $now = now()->utc()->toIso8601String();
        $row = $query->reorder()->selectRaw(
            'COUNT(*) AS total, '.
            'COUNT(*) FILTER (WHERE status = ? AND expires_at < ?) AS expired, '.
            'COUNT(*) FILTER (WHERE status = ? AND expires_at >= ?) AS valid',
            [PazSalvo::GENERATED, $now, PazSalvo::GENERATED, $now]
        )->first();

        return ['total' => (int) $row->total, 'expired' => (int) $row->expired, 'valid' => (int) $row->valid];
    }

    public function global(): array
    {
        return $this->count(PazSalvo::query()->whereIn('status', [PazSalvo::GENERATED, PazSalvo::CANCELLED]));
    }
}
