<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Http\Controllers\Controller;
use DbVault\Services\SchemaIntrospectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET requests/tables?database=appdb -> { database, tables: [...] }
 *
 * Powers the request form's table pick-list. `database` defaults to the
 * configured target and is validated against the same allow-list the store
 * endpoint uses (target_database + allowed_databases), so a caller cannot
 * probe arbitrary schemas. Table discovery itself is delegated to
 * SchemaIntrospectionService, which introspects a configured read
 * connection or falls back to the static catalog.
 */
class SchemaController extends Controller
{
    /**
     * GET requests/form-options -> { databases: [...], durations: [{value,label}] }
     *
     * The request form's static option lists (target databases, session
     * durations), sourced from config so operators control them via env
     * (DBVAULT_TARGET_DATABASE, DBVAULT_ALLOWED_DATABASES) rather than the
     * SPA shipping a hardcoded list.
     */
    public function formOptions(): JsonResponse
    {
        $databases = array_values(array_unique(array_merge(
            [config('dbvault.target_database')],
            config('dbvault.allowed_databases', [])
        )));

        $durations = array_map(
            static fn (int $minutes): array => [
                'value' => $minutes,
                'label' => $minutes < 60
                    ? "{$minutes} minutes"
                    : ($minutes % 60 === 0
                        ? ($minutes / 60).' hour'.($minutes === 60 ? '' : 's')
                        : "{$minutes} minutes"),
            ],
            config('dbvault.available_durations', [15, 30, 60, 120, 240])
        );

        return response()->json([
            'databases' => $databases,
            'durations' => $durations,
        ]);
    }

    public function tables(Request $request, SchemaIntrospectionService $schema): JsonResponse
    {
        $allowedDatabases = array_values(array_unique(array_merge(
            [config('dbvault.target_database')],
            config('dbvault.allowed_databases', [])
        )));

        $validated = $request->validate([
            'database' => ['nullable', 'string', 'max:64', Rule::in($allowedDatabases)],
        ]);

        $database = $validated['database'] ?? config('dbvault.target_database');

        return response()->json([
            'database' => $database,
            'tables' => $schema->tablesFor($database),
        ]);
    }
}
