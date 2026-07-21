<?php

declare(strict_types=1);

namespace DbVault\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \DbVault\Models\Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'label' => $this->label,
            'cert_dn' => $this->cert_dn,
            'cert_fingerprint' => $this->cert_fingerprint,
            'enrolled_at' => optional($this->enrolled_at)->toIso8601String(),
            'revoked_at' => optional($this->revoked_at)->toIso8601String(),
            'is_revoked' => $this->isRevoked(),
        ];
    }
}
