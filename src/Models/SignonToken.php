<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time token used to launch phpMyAdmin against a DbSession without
 * ever exposing the underlying MySQL credential to the developer. Only the
 * hash is persisted; the raw token is generated and handed off once by
 * DbVault\Http\Controllers\Api\DbSessionController::launch().
 *
 * @property int $id
 * @property int $db_session_id
 * @property string $token_hash
 */
class SignonToken extends Model
{
    use UsesVaultConnection;

    /**
     * @var string
     */
    protected $table = 'vault_signon_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'db_session_id',
        'token_hash',
        'used_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function dbSession(): BelongsTo
    {
        return $this->belongsTo(DbSession::class);
    }

    public function isConsumable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
