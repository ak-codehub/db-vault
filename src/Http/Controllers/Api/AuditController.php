<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Http\Controllers\Controller;
use DbVault\Http\Resources\AuditQueryResource;
use DbVault\Models\AuditQuery;
use Illuminate\Http\JsonResponse;

/**
 * GET audit -> { queries: [...] }. The full recent query log; the SPA
 * (resources/js/Views/Audit/Index.vue) applies developer/text filtering
 * client-side, so no query params are consumed here. Backing rows are
 * populated by the CloudWatch ingest job that tails the RDS MariaDB Audit
 * Plugin stream.
 */
class AuditController extends Controller
{
    public function index(): JsonResponse
    {
        $queries = AuditQuery::query()
            ->with('dbSession.accessRequest.user')
            ->latest('executed_at')
            ->limit(200)
            ->get();

        return response()->json([
            'queries' => AuditQueryResource::collection($queries)->resolve(),
        ]);
    }
}
