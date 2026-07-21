<?php

declare(strict_types=1);

namespace DbVault\Models;

use DbVault\Models\Concerns\UsesVaultConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An RBAC role: developer, approver, or admin.
 *
 * @property int $id
 * @property string $name
 */
class Role extends Model
{
    use UsesVaultConnection;

    /**
     * @var string
     */
    protected $table = 'vault_roles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    /**
     * Users holding this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'vault_role_user');
    }
}
