<?php

declare(strict_types=1);

namespace DbVault\Services;

use DbVault\Models\ActivityLog;
use DbVault\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Thin wrapper around DbVault\Models\ActivityLog::create() so every call
 * site automatically carries the requesting device's mTLS certificate DN
 * (set by DbVault\Http\Middleware\TrustClientCertificate as the
 * `client_cert_dn` request attribute) without every controller having to
 * know that detail.
 */
class ActivityLogger
{
    public function __construct(protected Request $request)
    {
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(?User $actor, string $action, ?Model $subject = null, array $meta = []): ActivityLog
    {
        return ActivityLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'device_dn' => $this->request->attributes->get('client_cert_dn'),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
