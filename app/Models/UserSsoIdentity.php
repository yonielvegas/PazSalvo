<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSsoIdentity extends Model
{
    protected $fillable = ['user_id', 'issuer', 'subject', 'created_by', 'verified_profile'];

    protected function casts(): array
    {
        return ['verified_profile' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
