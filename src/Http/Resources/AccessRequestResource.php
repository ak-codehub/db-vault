<?php

declare(strict_types=1);

namespace DbVault\Http\Resources;

use DbVault\Support\Presenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DbVault\Models\AccessRequest, shaped for both the requests list/detail
 * endpoints and the nested `request` key of the approvals queue.
 *
 * @mixin \DbVault\Models\AccessRequest
 */
class AccessRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scope = $this->relationLoaded('grants') ? Presenter::summarizeScope($this->grants) : null;

        return [
            'id' => $this->id,
            'database' => $this->target_database,
            'durationMinutes' => $this->duration_minutes,
            'duration' => Presenter::formatDuration($this->duration_minutes),
            'reason' => $this->reason,
            'status' => $this->status->badgeStatus(),
            'developer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'scope' => $scope['summary'] ?? null,
            'scopeTone' => $scope['tone'] ?? null,
            'requestedAt' => $this->requested_at?->toIso8601String(),
            'requestedAgo' => $this->requested_at?->diffForHumans(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
