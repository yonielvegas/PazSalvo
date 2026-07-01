<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PazSalvo extends Model
{
    use HasFactory;

    public const PROCESSING = 'processing';

    public const GENERATED = 'generated';

    public const ERROR = 'error';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_balance' => 'decimal:2', 'expired_balance' => 'decimal:2',
            'non_expired_balance' => 'decimal:2', 'issued_at' => 'datetime',
            'expires_at' => 'datetime', 'cancelled_at' => 'datetime',
            'raw_widergy_response' => 'array', 'certificate_snapshot' => 'array',
        ];
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function publicStatus(): string
    {
        if ($this->status === self::CANCELLED) {
            return 'cancelled';
        }
        if ($this->status !== self::GENERATED) {
            return 'not_found';
        }

        return now()->greaterThan($this->expires_at) ? 'expired' : 'valid';
    }

    protected function effectiveStatus(): Attribute
    {
        return Attribute::get(fn () => $this->publicStatus());
    }
}
