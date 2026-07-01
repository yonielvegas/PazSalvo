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
            'total_balance' => 'decimal:2', 'issued_at' => 'datetime',
            'expires_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    public function userSignature(): BelongsTo
    {
        return $this->belongsTo(UserSignature::class);
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

    protected function fullAddress(): Attribute
    {
        return Attribute::get(fn () => $this->client?->full_address ?? 'Sin dirección');
    }
}
