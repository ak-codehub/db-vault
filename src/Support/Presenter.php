<?php

declare(strict_types=1);

namespace DbVault\Support;

use DbVault\Enums\Privilege;
use Illuminate\Support\Collection;

/**
 * Small presentation helpers shared by controllers/resources that
 * summarize a request's grant matrix or a duration for JSON output. Kept
 * out of the models/controllers themselves so the same summary logic isn't
 * duplicated across the dashboard, requests, and approvals endpoints.
 */
class Presenter
{
    /**
     * Summarize a collection of DbVault\Models\RequestGrant into a short
     * "write · orders, order_items" / "read · all tables" style string,
     * plus a tone ('warn' for any write privilege, 'muted' for read-only)
     * matching the frontend's badge palette.
     *
     * @param  Collection<int, \DbVault\Models\RequestGrant>  $grants
     * @return array{summary: string, tone: string}
     */
    public static function summarizeScope(Collection $grants): array
    {
        $writePrivileges = [
            Privilege::Insert->value,
            Privilege::Update->value,
            Privilege::Delete->value,
            Privilege::Create->value,
            Privilege::Alter->value,
            Privilege::Index->value,
        ];

        $isWrite = $grants->contains(
            fn ($grant) => in_array(
                $grant->privilege instanceof Privilege ? $grant->privilege->value : (string) $grant->privilege,
                $writePrivileges,
                true
            )
        );

        $tables = $grants->pluck('table_name')->unique()->values();

        $tableList = match (true) {
            $tables->isEmpty() => 'all tables',
            $tables->count() <= 3 => $tables->implode(', '),
            default => $tables->count().' tables',
        };

        return [
            'summary' => ($isWrite ? 'write' : 'read').' · '.$tableList,
            'tone' => $isWrite ? 'warn' : 'muted',
        ];
    }

    /**
     * Format a minute count as a short human string, e.g. 30 => "30 minutes",
     * 60 => "1 hour", 150 => "2h 30m".
     */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} minutes";
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($remainder === 0) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        return "{$hours}h {$remainder}m";
    }

    /**
     * Turn a flat collection of DbVault\Models\RequestGrant rows into the
     * per-table boolean matrix shape the frontend's PrivilegeMatrix
     * component expects: one row per table with a boolean flag per allowed
     * privilege (select/insert/update/delete/create/alter/index).
     *
     * @param  Collection<int, \DbVault\Models\RequestGrant>  $grants
     * @return list<array<string, bool|string>>
     */
    public static function buildMatrixRows(Collection $grants): array
    {
        $byTable = $grants->groupBy('table_name');
        $privilegeColumns = array_map('strtolower', config('dbvault.allowed_privileges', []));

        return $byTable->map(function (Collection $rows, string $table) use ($privilegeColumns) {
            $privileges = $rows->map(
                fn ($grant) => strtolower($grant->privilege instanceof Privilege ? $grant->privilege->value : (string) $grant->privilege)
            );

            $row = ['table' => $table];

            foreach ($privilegeColumns as $privilege) {
                $row[$privilege] = $privileges->contains($privilege);
            }

            return $row;
        })->values()->all();
    }
}
