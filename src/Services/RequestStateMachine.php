<?php

declare(strict_types=1);

namespace DbVault\Services;

use DbVault\Enums\RequestStatus;
use DbVault\Models\AccessRequest;
use DomainException;

/**
 * Guards DbVault\Models\AccessRequest::$status transitions against a fixed
 * map, so no controller can silently move a request between states that
 * aren't a legal edge of the lifecycle.
 *
 * Wires the developer/approver-facing edges (submit, approve, reject,
 * cancel) plus the Approved -> Active edge set once
 * DbVault\Services\ProvisionerService::createSession() succeeds. The
 * remaining Active -> {Revoked, Expired, Ended} edges are driven by the
 * scheduled expiry command and explicit revoke.
 */
class RequestStateMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['active'],
        'active' => ['revoked', 'expired', 'ended'],
        'rejected' => [],
        'cancelled' => [],
        'revoked' => [],
        'expired' => [],
        'ended' => [],
    ];

    /**
     * Move $accessRequest to $to and persist it, or throw if that edge
     * isn't allowed from the request's current status.
     *
     * Submitting into PendingApproval (from Draft) stamps requested_at if
     * it hasn't been set yet, since that's the moment the request actually
     * enters the approval queue.
     *
     * @throws DomainException if the transition is not in the allowed map
     */
    public function transition(AccessRequest $accessRequest, RequestStatus $to): AccessRequest
    {
        $from = $accessRequest->status;

        if (! $this->canTransition($from, $to)) {
            throw new DomainException(
                "Cannot transition access request #{$accessRequest->id} from '{$from->value}' to '{$to->value}'."
            );
        }

        $attributes = ['status' => $to];

        if ($to === RequestStatus::PendingApproval && $accessRequest->requested_at === null) {
            $attributes['requested_at'] = now();
        }

        $accessRequest->update($attributes);

        return $accessRequest;
    }

    /**
     * Whether moving from $from to $to is a legal edge in the map, without
     * throwing or mutating anything.
     */
    public function canTransition(RequestStatus $from, RequestStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public function allowedFrom(RequestStatus $status): array
    {
        return self::TRANSITIONS[$status->value] ?? [];
    }
}
