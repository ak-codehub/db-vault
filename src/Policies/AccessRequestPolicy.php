<?php

declare(strict_types=1);

namespace DbVault\Policies;

use DbVault\Enums\RequestStatus;
use DbVault\Models\AccessRequest;
use DbVault\Models\User;

/**
 * Authorization for viewing and cancelling DbVault\Models\AccessRequest
 * records. Approve/reject are gated at the route level by the
 * `vault.role:approver,admin` middleware plus an explicit not-your-own-request
 * check in DbVault\Http\Controllers\Api\ApprovalController, since that
 * decision also depends on who is deciding, not just who owns the request.
 */
class AccessRequestPolicy
{
    /**
     * The owner may always view their own request; approvers/admins may
     * view any request so they can review or audit it.
     */
    public function view(User $user, AccessRequest $accessRequest): bool
    {
        return $accessRequest->user_id === $user->id
            || $user->hasRole('approver')
            || $user->hasRole('admin');
    }

    /**
     * Only the owner may cancel their own request, and only while it's
     * still sitting in the approval queue.
     */
    public function cancel(User $user, AccessRequest $accessRequest): bool
    {
        return $accessRequest->user_id === $user->id
            && $accessRequest->status === RequestStatus::PendingApproval;
    }
}
