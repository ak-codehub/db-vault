<?php

declare(strict_types=1);

namespace DbVault\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single approve/reject decision recorded against an AccessRequest.
 *
 * @mixin \DbVault\Models\Approval
 */
class ApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision,
            'note' => $this->note,
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'approver' => $this->whenLoaded('approver', fn () => [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ]),
        ];
    }
}
