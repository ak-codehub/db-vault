<?php

declare(strict_types=1);

namespace DbVault\Database\Seeders;

use DbVault\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the RBAC roles used throughout DB Vault. Idempotent — safe to run on
 * every deploy. `auditor` is included as an optional read-only audit role the
 * SPA already recognises (see resources/js/router.js); the core three are
 * developer/approver/admin.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['developer', 'approver', 'admin', 'auditor'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
