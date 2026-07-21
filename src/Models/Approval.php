<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single approve/reject decision recorded against an AccessRequest.
 *
 * @property int $id
 * @property int $access_request_id
 * @property int $approver_id
 * @property string $decision
 * @property string|null $note
 */
class Approval extends Model
{
    use UsesVaultConnection;

    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'vault_approvals';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'access_request_id',
        'approver_id',
        'decision',
        'note',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
