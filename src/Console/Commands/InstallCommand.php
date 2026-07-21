<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use DbVault\Database\Seeders\RoleSeeder;
use DbVault\Models\Role;
use DbVault\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        if (! $this->ensureVaultDatabaseExists()) {
            return self::FAILURE;
        }

        if (! $this->runMigrations()) {
            return self::FAILURE;
        }

        if (! $this->seedRoles()) {
            return self::FAILURE;
        }

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

    /**
     * Ensure the vault's storage database exists before migrating.
     *
     * A fresh install would otherwise die mid-run with a raw
     * "Unknown database 'dbvault'" PDOException — the single most common
     * install failure. The vault connection inherits the host's DB
     * credentials, so we can create the database ourselves: connect to the
     * server WITHOUT selecting a database and issue CREATE DATABASE IF NOT
     * EXISTS. SQLite needs no such step (the file is created on first open).
     */
    protected function ensureVaultDatabaseExists(): bool
    {
        $connection = config('dbvault.connection');

        // Sharing the host's default connection (legacy shared-schema): the
        // host DB already exists, nothing to create.
        if ($connection !== 'dbvault') {
            return true;
        }

        $config = (array) config('database.connections.dbvault', []);
        $driver = $config['driver'] ?? 'mysql';

        if ($driver === 'sqlite') {
            return true;
        }

        $database = $config['database'] ?? null;

        if (! $database) {
            $this->components->error('DB Vault storage database is not configured. Set DBVAULT_DB_DATABASE in .env and re-run.');

            return false;
        }

        // The database name is interpolated into a CREATE DATABASE statement
        // (identifiers cannot be bound as parameters). Reject anything that is
        // not a plain identifier rather than silently mangling it — creating a
        // differently-named database than intended would be a subtle, and for
        // an access-broker unacceptable, failure.
        if (! $this->isSafeIdentifier((string) $database)) {
            $this->components->error("Refusing to auto-create vault database \"{$database}\": the name must match ^[A-Za-z0-9_]+$. Create it manually or rename it.");

            return false;
        }

        try {
            // Test whether the database is already reachable.
            DB::connection('dbvault')->getPdo();
            $this->components->task("Vault database \"{$database}\" is present");

            return true;
        } catch (Throwable $e) {
            // Fall through to attempt creation only for a missing-database
            // error; anything else (bad credentials, host down) is surfaced.
            if (! $this->isUnknownDatabaseError($e)) {
                $this->components->error('Could not reach the vault database connection: '.$e->getMessage());

                return false;
            }
        }

        // Build a bootstrap connection to the same server with NO database
        // selected, then create the vault database.
        $bootstrapName = 'dbvault_bootstrap';
        $bootstrapConfig = $config;
        unset($bootstrapConfig['database']);
        $bootstrapConfig['database'] = null;

        Config::set('database.connections.'.$bootstrapName, $bootstrapConfig);

        try {
            // $database is validated as ^[A-Za-z0-9_]+$ above, so it is safe to
            // quote and interpolate. charset/collation come from connection
            // config; whitelist them defensively before interpolation since
            // identifiers/keywords cannot be bound as query parameters.
            $quoted = $this->quoteIdentifier($database, $driver);

            $sql = "CREATE DATABASE {$quoted}";

            if ($driver !== 'pgsql') {
                $charset = $this->safeToken($config['charset'] ?? 'utf8mb4', 'utf8mb4');
                $collation = $this->safeToken($config['collation'] ?? 'utf8mb4_unicode_ci', 'utf8mb4_unicode_ci');
                $sql = "CREATE DATABASE IF NOT EXISTS {$quoted} CHARACTER SET {$charset} COLLATE {$collation}";
            }

            DB::connection($bootstrapName)->statement($sql);
            DB::purge($bootstrapName);

            $this->components->task("Created vault database \"{$database}\"");

            return true;
        } catch (Throwable $e) {
            $this->components->error("Could not create the vault database \"{$database}\": ".$e->getMessage());
            $this->line('  Create it manually, then re-run:  CREATE DATABASE '.$database.';');

            return false;
        }
    }

    protected function isUnknownDatabaseError(Throwable $e): bool
    {
        $message = $e->getMessage();

        // MySQL 1049 / generic "Unknown database", Postgres "does not exist".
        return str_contains($message, '1049')
            || str_contains($message, 'Unknown database')
            || str_contains($message, 'does not exist');
    }

    /**
     * A plain SQL identifier: letters, digits, underscores only.
     */
    protected function isSafeIdentifier(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }

    /**
     * Whitelist a charset/collation token, falling back to a safe default when
     * the configured value contains anything unexpected.
     */
    protected function safeToken(string $value, string $fallback): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? $value : $fallback;
    }

    protected function quoteIdentifier(string $name, string $driver): string
    {
        // $name is pre-validated by isSafeIdentifier(); quote per driver.
        return $driver === 'mysql' ? "`{$name}`" : "\"{$name}\"";
    }

    protected function runMigrations(): bool
    {
        $connection = config('dbvault.connection');

        // Run ONLY the package's migrations, and put BOTH the tables and the
        // migration-tracking repository on the vault connection. This keeps
        // "has this migration run?" bookkeeping in the same database as the
        // tables — otherwise the migrator reads the host's default `migrations`
        // table, can decide "Nothing to migrate" (e.g. after an earlier
        // attempt), and skip creating the vault_* tables while still exiting 0.
        //
        // The migration path is resolved from THIS file's location (__DIR__),
        // so it is correct regardless of where the package is installed
        // (vendor/, a path-repo symlink, or a differently-named directory) —
        // a hardcoded vendor/ path silently migrates nothing when it is wrong.
        $migrationsPath = realpath(__DIR__.'/../../../database/migrations');

        $options = array_filter([
            '--path' => $migrationsPath ?: null,
            '--realpath' => $migrationsPath ? true : null,
            '--database' => $connection ?: null,
            '--force' => true,
        ], static fn ($v) => $v !== null);

        try {
            Artisan::call('migrate', $options);
        } catch (Throwable $e) {
            $this->components->error('Vault migrations failed: '.$e->getMessage());
            $this->line('  '.trim((string) Artisan::output()));

            return false;
        }

        // Verify the core table actually exists — a wrong path or a silent
        // "Nothing to migrate" would otherwise report success while leaving
        // the vault unusable.
        if (! $this->vaultSchemaReady()) {
            $this->components->error('Vault migrations ran but the vault_users table is missing. Check DBVAULT_DB_* and the migration path.');
            $this->line('  '.trim((string) Artisan::output()));

            return false;
        }

        $this->components->task('Ran vault migrations on the "'.$connection.'" connection');

        return true;
    }

    protected function vaultSchemaReady(): bool
    {
        $connection = config('dbvault.connection') ?: null;

        try {
            return DB::connection($connection)->getSchemaBuilder()->hasTable('vault_users');
        } catch (Throwable) {
            return false;
        }
    }

    protected function seedRoles(): bool
    {
        try {
            (new RoleSeeder())->setContainer($this->laravel)->run();
        } catch (Throwable $e) {
            $this->components->error('Seeding RBAC roles failed: '.$e->getMessage());

            return false;
        }

        $this->components->task('Seeded RBAC roles (developer, approver, admin, auditor)');

        return true;
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
