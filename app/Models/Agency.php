<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agency extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pazSalvos(): HasMany
    {
        return $this->hasMany(PazSalvo::class);
    }

    public function userSignatures(): HasMany
    {
        return $this->hasMany(UserSignature::class);
    }

    public function activeSignature(): HasOne
    {
        return $this->hasOne(UserSignature::class)->where('is_active', true);
    }
}
