<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use DbVault\Enums\Privilege;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single (table, [column], privilege) tuple requested against an
 * AccessRequest. `privilege` must belong to config('dbvault.allowed_privileges');
 * DROP/TRIGGER can never appear here (see DbVault\Services\ProvisionerService).
 *
 * @property int $id
 * @property int $access_request_id
 * @property string $table_name
 * @property string|null $column_name
 * @property string $privilege
 */
class RequestGrant extends Model
{
    use UsesVaultConnection;

    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'vault_request_grants';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'access_request_id',
        'table_name',
        'column_name',
        'privilege',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'privilege' => Privilege::class,
        ];
    }

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }
}
