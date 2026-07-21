<?php

declare(strict_types=1);

namespace DbVault\Enums;

/**
 * MySQL privileges that may be requested/granted via the vault. Values are
 * the literal MySQL GRANT keywords. DROP and TRIGGER are intentionally NOT
 * represented here - they are never grantable, and this omission is backed
 * by a structural check in DbVault\Services\ProvisionerService::buildGrantSql().
 *
 * @see config('dbvault.allowed_privileges')
 * @see config('dbvault.forbidden_privileges')
 */
enum Privilege: string
{
    case Select = 'SELECT';
    case Insert = 'INSERT';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Create = 'CREATE';
    case Alter = 'ALTER';
    case Index = 'INDEX';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
