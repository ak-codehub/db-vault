<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A vault operator: a developer, approver, and/or admin. This is the
 * vault's OWN user - entirely separate from the host application's own
 * users table. Authenticates on the `vault` guard (see
 * DbVaultServiceProvider::registerGuard()) via local email/password plus
 * TOTP or email-OTP 2FA (DbVault\Services\TwoFactorService).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property string|null $email_otp_code
 * @property \Illuminate\Support\Carbon|null $email_otp_expires_at
 * @property bool $is_active
 */
class User extends Authenticatable
{
    use UsesVaultConnection;

    use HasFactory;
    use Notifiable;

    /**
     * @var string
     */
    protected $table = 'vault_users';

    /**
     * @var string
     */
    protected $guard_name = 'vault';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'email_otp_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'email_otp_expires_at' => 'datetime',
        ];
    }

    /**
     * Roles assigned to this user (developer, approver, admin).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'vault_role_user');
    }

    /**
     * Access requests submitted by this user.
     */
    public function accessRequests(): HasMany
    {
        return $this->hasMany(AccessRequest::class);
    }

    /**
     * Enrolled mTLS client-certificate devices belonging to this user.
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Approvals decided by this user, when acting as an approver.
     */
    public function approvalsDecided(): HasMany
    {
        return $this->hasMany(Approval::class, 'approver_id');
    }

    /**
     * Whether this user holds the given role, e.g. $user->hasRole('approver').
     */
    public function hasRole(string $role): bool
    {
        return $this->roles->contains('name', $role);
    }

    /**
     * Whether this user has confirmed a TOTP secret (2FA enabled).
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
