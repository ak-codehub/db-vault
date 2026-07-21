<?php

declare(strict_types=1);

namespace DbVault\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \DbVault\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles->pluck('name')->values(),
            'is_active' => (bool) $this->is_active,
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
