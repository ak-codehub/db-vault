<?php

declare(strict_types=1);

namespace DbVault\Http\Resources;

use DbVault\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DbVault\Models\DbSession (a provisioned temporary MySQL user), shaped
 * for the sessions/dashboard endpoints. Never exposes the underlying MySQL
 * credential - only the generated username.
 *
 * @mixin \DbVault\Models\DbSession
 */
class DbSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accessRequest = $this->whenLoaded('accessRequest');
        $scope = $accessRequest && $accessRequest->relationLoaded('grants')
            ? Presenter::summarizeScope($accessRequest->grants)
            : null;

        return [
            'id' => $this->id,
            'requestId' => $this->access_request_id,
            'username' => $this->mysql_username,
            'developer' => $accessRequest?->user?->name,
            'database' => $accessRequest?->target_database,
            'scope' => $scope['summary'] ?? null,
            'scopeTone' => $scope['tone'] ?? null,
            'status' => $this->status->badgeStatus(),
            'provisionedAt' => $this->provisioned_at?->toIso8601String(),
            'expiresAt' => $this->expires_at?->toIso8601String(),
            'expiresIn' => $this->expires_at?->diffForHumans(null, true),
        ];
    }
}
