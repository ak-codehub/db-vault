<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use DbVault\Models\Role;
use DbVault\Models\User;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Create (or update) a DB Vault admin user.
 *
 * Kept separate from db-vault:install on purpose: install sets up the schema
 * and must never block on an interactive prompt (it may run in CI, piped, or
 * unattended). Creating a human admin is its own concern and can be run at any
 * time — for the first admin, or to add more later.
 *
 *   php artisan db-vault:admin
 *     --name= --email= --password=   (skip individual prompts)
 *     --role=admin                   (developer|approver|admin|auditor; default admin)
 *
 * Non-interactive (CI): pass all of --name/--email/--password, or set
 * DBVAULT_ADMIN_NAME / DBVAULT_ADMIN_EMAIL / DBVAULT_ADMIN_PASSWORD.
 */
class AdminCommand extends Command
{
    protected $signature = 'db-vault:admin
        {--name= : Full name of the user}
        {--email= : Email (login identifier)}
        {--password= : Password (prompted securely if omitted)}
        {--role=admin : Role to grant: developer|approver|admin|auditor}';

    protected $description = 'Create or update a DB Vault user and grant a role.';

    public function handle(): int
    {
        $role = strtolower((string) $this->option('role'));
        $validRoles = ['developer', 'approver', 'admin', 'auditor'];

        if (! in_array($role, $validRoles, true)) {
            $this->components->error("Invalid --role '{$role}'. Use one of: ".implode(', ', $validRoles).'.');

            return self::FAILURE;
        }

        $roleId = Role::where('name', $role)->value('id');

        if ($roleId === null) {
            $this->components->error("Role '{$role}' does not exist. Run `php artisan db-vault:install` first to seed roles.");

            return self::FAILURE;
        }

        [$name, $email, $plainPassword] = $this->resolveDetails();

        if ($name === null || $email === null || $plainPassword === null) {
            $this->components->error('Name, email and password are all required. Provide them via prompts, --name/--email/--password, or DBVAULT_ADMIN_* env vars.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        try {
            $existing = User::where('email', $email)->exists();

            $user = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => $plainPassword, 'is_active' => true],
            );

            $user->roles()->syncWithoutDetaching([$roleId]);
        } catch (Throwable $e) {
            $this->components->error('Could not save the user: '.$e->getMessage());

            return self::FAILURE;
        }

        $verb = $existing ? 'Updated' : 'Created';
        $this->components->info("{$verb} {$role} user {$email}.");

        return self::SUCCESS;
    }

    /**
     * Resolve name/email/password from options, then env, then interactive
     * prompts (only for the pieces still missing, and only when interactive).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    protected function resolveDetails(): array
    {
        $name = $this->option('name') ?: env('DBVAULT_ADMIN_NAME');
        $email = $this->option('email') ?: env('DBVAULT_ADMIN_EMAIL');
        $password = $this->option('password') ?: env('DBVAULT_ADMIN_PASSWORD');

        if ($this->input->isInteractive()) {
            $name = $name ?: text('Full name', required: true);
            $email = $email ?: text('Email', required: true);
            $password = $password ?: promptPassword('Password', required: true);
        }

        return [
            $name ?: null,
            $email ?: null,
            $password ?: null,
        ];
    }
}
