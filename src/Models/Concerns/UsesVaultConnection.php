<?php

declare(strict_types=1);

namespace DbVault\Models\Concerns;

/**
 * Binds a vault model to the vault's dedicated storage connection
 * (config('dbvault.connection')) rather than the host application's default
 * connection. This keeps every vault_* table in the vault's own database,
 * isolated from the host app's schema and from the target database whose
 * access the vault brokers.
 *
 * Resolved at call time (not cached in a $connection property) so config
 * changes — e.g. the test suite swapping connections — take effect
 * immediately.
 */
trait UsesVaultConnection
{
    public function getConnectionName(): ?string
    {
        return config('dbvault.connection') ?: parent::getConnectionName();
    }
}
