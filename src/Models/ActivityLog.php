<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only activity/audit trail for actions taken within the vault
 * itself (logins, requests, approvals, provisioning, revocations, signon
 * launches, etc). Distinct from AuditQuery, which records actual SQL
 * executed against the brokered target database.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $device_dn
 * @property array|null $meta
 */
class ActivityLog extends Model
{
    use UsesVaultConnection;

    const UPDATED_AT = null;

    /**
     * Eloquent's naming convention would guess 'vault_activity_logs'; this
     * is deliberately 'vault_activity_log' since it's an append-only log
     * rather than a collection of "logs".
     *
     * @var string
     */
    protected $table = 'vault_activity_log';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'device_dn',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Polymorphic relation to the subject of the logged action (e.g. an
     * AccessRequest, DbSession, Device, ...).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
