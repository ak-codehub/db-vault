<?php

declare(strict_types=1);

namespace DbVault\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single query executed under a DbSession, as bound by the CloudWatch
 * ingest job from the RDS MariaDB Audit Plugin stream.
 *
 * @mixin \DbVault\Models\AuditQuery
 */
class AuditQueryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dbSession = $this->whenLoaded('dbSession');
        $accessRequest = $dbSession?->relationLoaded('accessRequest') ? $dbSession->accessRequest : null;

        return [
            'id' => $this->id,
            'developer' => $accessRequest?->user?->name ?? 'Unknown',
            'username' => $dbSession?->mysql_username,
            'statement' => $this->statement,
            'sourceIp' => $this->source_ip,
            'at' => $this->executed_at?->format('H:i:s'),
            'executedAt' => $this->executed_at?->toIso8601String(),
        ];
    }
}
