<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use DbVault\Database\Seeders\RoleSeeder;
use DbVault\Models\Role;
use DbVault\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Guided first-time setup for the db-vault package inside a host Laravel 12
 * app: publish the config + compiled SPA assets, run the vault migrations,
 * seed the RBAC roles, and create the first admin user. Every step is
 * idempotent — re-running is safe and skips work already done.
 *
 * Infrastructure (Secrets Manager, IAM, the RDS audit plugin, the mTLS CA,
 * nginx, phpMyAdmin signon) is out of scope here — see Phase-0-Infra-Runbook.md
 * and DB-Vault-Design.md.
 *
 * With --no-interaction, prompts are skipped and the first admin is created
 * from DBVAULT_ADMIN_NAME / DBVAULT_ADMIN_EMAIL / DBVAULT_ADMIN_PASSWORD if
 * all three are present.
 */
class InstallCommand extends Command
{
    protected $signature = 'db-vault:install {--force : Overwrite any published files that already exist}';

    protected $description = 'Publish assets/config, migrate, seed roles, and create the first DB Vault admin.';

    public function handle(): int
    {
        $this->components->info('DB Vault installer');

        $this->publishAssets();
        $this->runMigrations();
        $this->seedRoles();
        $this->createFirstAdmin();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    protected function publishAssets(): void
    {
        Artisan::call('vendor:publish', array_filter([
            '--tag' => 'db-vault-config',
            '--force' => $this->option('force'),
        ]));

        Artisan::call('vendor:publish', array_filter([
            '--tag' => 'db-vault-assets',
            '--force' => $this->option('force'),
        ]));

        $this->components->task('Published config and SPA assets');
    }

    protected function runMigrations(): void
    {
        // Each vault migration binds itself to config('dbvault.connection')
        // (see its constructor), so Schema builds every vault_* table on the
        // vault's own database regardless of the host's default connection.
        // A plain migrate is therefore safe: host migrations stay on the
        // host connection, vault migrations self-route to the vault one.
        Artisan::call('migrate', ['--force' => true]);
        $this->components->task('Ran vault migrations on the "'.config('dbvault.connection').'" connection');
    }

    protected function seedRoles(): void
    {
        (new RoleSeeder())->setContainer($this->laravel)->run();
        $this->components->task('Seeded RBAC roles (developer, approver, admin, auditor)');
    }

    protected function createFirstAdmin(): void
    {
        if (User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->exists()) {
            $this->components->info('An admin user already exists — skipping first-admin creation.');

            return;
        }

        [$name, $email, $plainPassword] = $this->resolveAdminDetails();

        if ($name === null || $email === null || $plainPassword === null) {
            $this->components->warn('No admin created. Run `php artisan db-vault:install` interactively, or set DBVAULT_ADMIN_* env vars.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $plainPassword, 'is_active' => true],
        );

        $adminRoleId = Role::where('name', 'admin')->value('id');
        $user->roles()->syncWithoutDetaching([$adminRoleId]);

        $this->components->task("Created admin user {$email}");
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    protected function resolveAdminDetails(): array
    {
        if (! $this->input->isInteractive()) {
            return [
                env('DBVAULT_ADMIN_NAME'),
                env('DBVAULT_ADMIN_EMAIL'),
                env('DBVAULT_ADMIN_PASSWORD'),
            ];
        }

        if (! confirm('Create the first admin user now?', default: true)) {
            return [null, null, null];
        }

        return [
            text('Admin name', required: true),
            text('Admin email', required: true),
            password('Admin password', required: true),
        ];
    }

    protected function printNextSteps(): void
    {
        $path = trim((string) config('dbvault.path', 'vault'), '/');

        $this->components->info('DB Vault is installed.');
        $this->line("  • Panel URL:  /{$path}");
        $this->line('  • Front it with the mTLS nginx vhost from Phase-0-Infra-Runbook.md');
        $this->line('  • Schedule cleanup:  Schedule::command(\'dbvault:drop-expired-sessions\')->everyFiveMinutes();');
    }
}
