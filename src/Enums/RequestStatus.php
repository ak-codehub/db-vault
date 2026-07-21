<?php

declare(strict_types=1);

namespace DbVault\Enums;

/**
 * Lifecycle states of a DbVault\Models\AccessRequest.
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Active = 'active';
    case Expired = 'expired';
    case Ended = 'ended';
    case Revoked = 'revoked';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Ended => 'Ended',
            self::Revoked => 'Revoked',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses in which a request still requires human attention.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Draft, self::PendingApproval, self::Approved, self::Active];
    }

    /**
     * Maps this status onto the fixed vocabulary understood by the
     * frontend's StatusBadge component (active/pending/rejected/... -> a
     * tone), since the UI's badge palette is coarser than the vault's
     * actual lifecycle states.
     */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::PendingApproval => 'pending',
            self::Approved => 'approved',
            self::Active => 'active',
            self::Expired => 'expired',
            self::Revoked => 'revoked',
            self::Rejected => 'rejected',
            self::Ended, self::Cancelled => 'muted',
        };
    }
}
