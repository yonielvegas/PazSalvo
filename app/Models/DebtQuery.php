<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DebtQuery extends Model
{
    use HasFactory;

    public const DEBT_FREE = 'debt_free';

    public const HAS_DEBT = 'has_debt';

    public const NOT_FOUND = 'not_found';

    public const ERROR = 'error';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_balance' => 'decimal:2',
            'expired_balance' => 'decimal:2',
            'non_expired_balance' => 'decimal:2',
            'next_expiration_on' => 'date',
            'raw_response' => 'array',
            'queried_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DebtItem::class);
    }

    public function pazSalvo(): HasOne
    {
        return $this->hasOne(PazSalvo::class);
    }
}
