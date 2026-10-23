<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasSingleRole;
use App\Services\AdministrativeTransaction;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const NO_ROLE_LOGIN_MESSAGE = 'Su usuario no tiene un rol asignado. Contacte al administrador del sistema.';

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasSingleRole, Notifiable {
        HasSingleRole::assignRole insteadof HasRoles;
        HasSingleRole::syncRoles insteadof HasRoles;
        HasSingleRole::removeRole insteadof HasRoles;
        HasRoles::checkPermissionTo as private spatieCheckPermissionTo;
    }

    public function checkPermissionTo($permission, ?string $guardName = null): bool
    {
        // Direct permissions remain supported, but cannot make a roleless/inactive user operational.
        return $this->is_active && static::whereKey($this->id)->where('is_active', true)->exists()
            && $this->hasOperationalRole() && $this->spatieCheckPermissionTo($permission, $guardName);
    }

    public function save(array $options = [])
    {
        if (! $this->exists || ! $this->isDirty('is_active')) {
            return parent::save($options);
        }

        return AdministrativeTransaction::run(function () use ($options) {
            $current = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            AdministrativeTransaction::protectLastAdmin($current, (bool) $this->is_active);
            if ($this->is_active && ! $current->hasOperationalRole()) {
                throw ValidationException::withMessages(['role' => self::NO_ROLE_LOGIN_MESSAGE]);
            }
            AdministrativeTransaction::accessChanged($current);
            $this->session_version = max((int) $this->session_version, (int) $current->session_version + 1);

            return parent::save($options);
        });
    }

    public function delete()
    {
        return AdministrativeTransaction::run(function () {
            $current = static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            AdministrativeTransaction::protectLastAdmin($current, false);

            AdministrativeTransaction::accessChanged($current);

            return parent::delete();
        });
    }

    protected $guarded = [];

    public static function normalizeUsername(?string $value): ?string
    {
        $value = $value === null ? '' : mb_strtolower(trim($value), 'UTF-8');

        return $value === '' ? null : $value;
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: fn ($value) => self::normalizeUsername($value));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_login_blocked' => 'boolean',
            'login_attempts' => 'integer',
            'session_version' => 'integer',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password_reset_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function ssoIdentity(): HasOne
    {
        return $this->hasOne(UserSsoIdentity::class);
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

    public function passwordResetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'password_reset_by');
    }
}
