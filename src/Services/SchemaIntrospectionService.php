<?php

declare(strict_types=1);

namespace DbVault\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lists the tables a developer may request access to for a given target
 * database, so the request form can offer a pick-list instead of relying on
 * the operator to type exact table names.
 *
 * Resolution order:
 *
 *  1. If a dedicated read connection is configured for the target
 *     (config('dbvault.introspection_connection')), introspect it live via
 *     INFORMATION_SCHEMA (MySQL/MariaDB) or sqlite_master (SQLite, used by
 *     the test host app).
 *  2. Otherwise fall back to the static config('dbvault.browsable_tables')
 *     catalog. This keeps the form usable in the same environments where
 *     the ProvisionerService is still a stub (no live RDS reachable).
 *
 * Introspection is read-only: it only ever runs SHOW/SELECT against
 * INFORMATION_SCHEMA and never touches the target data itself.
 */
class SchemaIntrospectionService
{
    /**
     * Return a sorted, de-duplicated list of table names available for the
     * given target database. Falls back to the config catalog on any
     * connection/introspection failure so the form never hard-breaks.
     *
     * @return list<string>
     */
    public function tablesFor(string $database): array
    {
        $connectionName = config('dbvault.introspection_connection');

        $liveIntrospected = false;
        $tables = $this->catalogFallback();

        if ($connectionName) {
            try {
                $tables = $this->introspect(DB::connection($connectionName), $database);
                $liveIntrospected = true;
            } catch (Throwable) {
                // Live introspection failed (unreachable target, missing
                // grants, etc.) — keep the static catalog rather than
                // surfacing a 500 to the request form.
            }
        }

        // Allowlist (browsable_tables) only filters LIVE-introspected results.
        // Without a live connection the fallback catalog already *is* the
        // browsable list, so re-filtering it by itself would be redundant.
        if ($liveIntrospected) {
            $tables = $this->applyAllowlist($tables);
        }

        return $this->applyDenylist($tables);
    }

    /**
     * Keep only tables matching config('dbvault.browsable_tables'). An empty
     * list (or a single "*") means "no allowlist" — every table passes.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function applyAllowlist(array $tables): array
    {
        $patterns = $this->patterns('dbvault.browsable_tables');

        if ($patterns === [] || in_array('*', $patterns, true)) {
            return $tables;
        }

        return array_values(array_filter(
            $tables,
            fn (string $table): bool => $this->matchesAny($table, $patterns),
        ));
    }

    /**
     * Drop any table matching config('dbvault.restricted_tables') so
     * sensitive tables never reach the request form.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function applyDenylist(array $tables): array
    {
        $patterns = $this->patterns('dbvault.restricted_tables');

        if ($patterns === []) {
            return $tables;
        }

        return array_values(array_filter(
            $tables,
            fn (string $table): bool => ! $this->matchesAny($table, $patterns),
        ));
    }

    /**
     * @return list<string>
     */
    protected function patterns(string $configKey): array
    {
        return array_values(array_filter(array_map(
            static fn ($p): string => strtolower(trim((string) $p)),
            (array) config($configKey, [])
        ), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Case-insensitive match with trailing-'*' prefix wildcard support.
     *
     * @param  list<string>  $patterns
     */
    protected function matchesAny(string $table, array $patterns): bool
    {
        $lower = strtolower($table);

        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($lower, rtrim($pattern, '*'))) {
                    return true;
                }
            } elseif ($lower === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function introspect(ConnectionInterface $connection, string $database): array
    {
        $driver = $connection->getDriverName();

        // NOTE: information_schema column names come back UPPER-CASE on
        // MySQL 8 (TABLE_NAME), so we must alias to a stable lower-case key
        // ("name") — otherwise pluck('table_name') silently returns empty
        // values for every row and the whole result is dropped.
        $names = match ($driver) {
            'mysql', 'mariadb' => $connection
                ->table('information_schema.tables')
                ->where('table_schema', $database)
                ->where('table_type', 'BASE TABLE')
                ->pluck('TABLE_NAME as name'),
            'sqlite' => $connection
                ->table('sqlite_master')
                ->where('type', 'table')
                ->where('name', 'not like', 'sqlite_%')
                ->pluck('name'),
            'pgsql' => $connection
                ->table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where('table_type', 'BASE TABLE')
                ->pluck('table_name as name'),
            default => collect(),
        };

        return $this->normalise($names->values()->all());
    }

    /**
     * @return list<string>
     */
    protected function catalogFallback(): array
    {
        return $this->normalise((array) config('dbvault.browsable_tables', []));
    }

    /**
     * @param  array<int, mixed>  $names
     * @return list<string>
     */
    protected function normalise(array $names): array
    {
        $clean = array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            $names
        ));

        $unique = array_values(array_unique($clean));
        sort($unique, SORT_NATURAL | SORT_FLAG_CASE);

        return $unique;
    }
}
