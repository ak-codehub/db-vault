<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use DbVault\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provisioned, per-request temporary MySQL user/session. Created by
 * DbVault\Services\ProvisionerService on approval and dropped at expiry,
 * logout, or revoke by the scheduled cleanup command.
 *
 * @property int $id
 * @property int $access_request_id
 * @property string $mysql_username
 * @property SessionStatus $status
 * @property int $max_connections
 */
class DbSession extends Model
{
    use UsesVaultConnection;

    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'vault_db_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'access_request_id',
        'mysql_username',
        'secret',
        'status',
        'provisioned_at',
        'expires_at',
        'dropped_at',
        'max_connections',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'secret' => 'encrypted',
            'provisioned_at' => 'datetime',
            'expires_at' => 'datetime',
            'dropped_at' => 'datetime',
            'max_connections' => 'integer',
        ];
    }

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }

    /**
     * Audited queries executed under this session, bound by the CloudWatch
     * ingest job.
     */
    public function auditQueries(): HasMany
    {
        return $this->hasMany(AuditQuery::class);
    }

    /**
     * One-time phpMyAdmin signon tokens issued for this session.
     */
    public function signonTokens(): HasMany
    {
        return $this->hasMany(SignonToken::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
