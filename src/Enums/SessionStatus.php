<?php

declare(strict_types=1);

namespace DbVault\Enums;

/**
 * Lifecycle states of a DbVault\Models\DbSession (a provisioned temporary
 * MySQL user).
 */
enum SessionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Dropped = 'dropped';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Dropped => 'Dropped',
            self::Revoked => 'Revoked',
        };
    }

    /**
     * Statuses in which the underlying MySQL user still exists and must
     * eventually be dropped by the scheduled cleanup job.
     *
     * @return array<int, self>
     */
    public static function live(): array
    {
        return [self::Pending, self::Active];
    }

    /**
     * Maps this status onto the fixed vocabulary understood by the
     * frontend's StatusBadge component.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Active => 'active',
            self::Expired => 'expired',
            self::Revoked => 'revoked',
            self::Dropped => 'muted',
        };
    }
}
