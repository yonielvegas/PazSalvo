<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\GeneralAdminSignature;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_login_blocked' => 'boolean',
            'login_attempts' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function generatedPazSalvos(): HasMany
    {
        return $this->hasMany(PazSalvo::class, 'generated_by');
    }

    public function isSupervisor(): bool
    {
        return $this->hasRole('supervisor');
    }

    public function isGeneralAdmin(): bool
    {
        return $this->hasRole('administrador_general');
    }

    public function generalAdminSignatures(): HasMany
    {
        return $this->hasMany(GeneralAdminSignature::class);
    }

    public function activeGeneralAdminSignature(): HasOne
    {
        return $this->hasOne(GeneralAdminSignature::class)->where('is_active', true);
    }
}
