<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use DbVault\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A developer's request for temporary, scoped access to a target database.
 * Carries the requested grant matrix (RequestGrant rows), its single
 * Approval decision, and - once approved - the provisioned DbSession.
 *
 * @property int $id
 * @property int $user_id
 * @property string $target_database
 * @property int $duration_minutes
 * @property string $reason
 * @property RequestStatus $status
 */
class AccessRequest extends Model
{
    use UsesVaultConnection;

    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'vault_access_requests';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'target_database',
        'duration_minutes',
        'reason',
        'status',
        'requested_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'requested_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The requested grant matrix: one row per (table, [column], privilege).
     */
    public function grants(): HasMany
    {
        return $this->hasMany(RequestGrant::class);
    }

    public function approval(): HasOne
    {
        return $this->hasOne(Approval::class);
    }

    public function dbSession(): HasOne
    {
        return $this->hasOne(DbSession::class);
    }
}
