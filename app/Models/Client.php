<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function fullAddress(): Attribute
    {
        return Attribute::get(function () {
            $parts = collect([$this->district, $this->corregimiento, $this->city, $this->address])
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->values();

            return $parts->isEmpty() ? 'Sin dirección' : $parts->implode(' - ');
        });
    }

    public function pazSalvos(): HasMany
    {
        return $this->hasMany(PazSalvo::class);
    }
}
