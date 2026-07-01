<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payable' => 'boolean', 'issued_on' => 'date', 'first_expiration_on' => 'date'];
    }

    public function debtQuery(): BelongsTo
    {
        return $this->belongsTo(DebtQuery::class);
    }
}
