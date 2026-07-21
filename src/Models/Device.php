<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An enrolled client-certificate device used to satisfy the mTLS leg of
 * authentication. `cert_fingerprint` and `cert_dn` are matched against the
 * headers the reverse proxy forwards after terminating the TLS handshake.
 *
 * @property int $id
 * @property int $user_id
 * @property string $cert_fingerprint
 * @property string $cert_dn
 * @property string|null $label
 */
class Device extends Model
{
    use UsesVaultConnection;

    /**
     * @var string
     */
    protected $table = 'vault_devices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'cert_fingerprint',
        'cert_dn',
        'label',
        'enrolled_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
