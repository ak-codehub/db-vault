<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single query executed under a DbSession, as bound by the CloudWatch
 * ingest job from the RDS MariaDB Audit Plugin stream.
 *
 * @property int $id
 * @property int $db_session_id
 * @property string $statement
 * @property string|null $source_ip
 */
class AuditQuery extends Model
{
    use UsesVaultConnection;

    public $timestamps = true;

    /**
     * @var string
     */
    protected $table = 'vault_audit_queries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'db_session_id',
        'statement',
        'source_ip',
        'executed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }

    public function dbSession(): BelongsTo
    {
        return $this->belongsTo(DbSession::class);
    }
}
