<?php

declare(strict_types=1);

namespace DbVault\Services;

use DbVault\Enums\Privilege;
use DbVault\Enums\SessionStatus;
use DbVault\Models\AccessRequest;
use DbVault\Models\DbSession;
use DbVault\Models\RequestGrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Stub. On approval, the Provisioner is responsible for:
 *
 *  1. Reading the MASTER MySQL credential from Secrets Manager
 *     (see SecretsManagerService::getMasterCredential()).
 *  2. Connecting to the target RDS instance as that master user.
 *  3. Generating a unique, per-session MySQL username
 *     (config('dbvault.temp_user_prefix') . "{$devHandle}_req{$requestId}").
 *  4. Running CREATE USER ... REQUIRE SSL ... WITH MAX_USER_CONNECTIONS,
 *     followed by the granular GRANT statements assembled from the
 *     request's grant matrix (see buildGrantSql()).
 *  5. Persisting the resulting DbVault\Models\DbSession row.
 *
 * DROP and TRIGGER must never be granted. This is enforced structurally in
 * buildGrantSql(), which throws if either appears anywhere in the matrix,
 * regardless of what an access_requests/request_grants row claims.
 *
 * dropSession() is invoked by the scheduled cleanup command, on explicit
 * revoke, and on developer logout, and is responsible for DROP USER on the
 * target RDS instance and marking the DbSession dropped.
 */
class ProvisionerService
{
    public function __construct(
        protected SecretsManagerService $secretsManager,
    ) {
    }

    /**
     * Provision a temporary, scoped MySQL user for an approved AccessRequest
     * and record the resulting DbSession.
     *
     * TODO:
     *  - $credential = $this->secretsManager->getMasterCredential();
     *  - open a PDO connection to config('dbvault.rds_host') as the master user
     *  - CREATE USER '<username>'@'%' IDENTIFIED BY '<generated secret>'
     *      REQUIRE SSL
     *      WITH MAX_USER_CONNECTIONS <config('dbvault.max_user_connections')>
     *  - execute $this->buildGrantSql($accessRequest->grants) against
     *      config('dbvault.target_database')
     *  - persist the generated password only long enough to hand it to the
     *    phpMyAdmin one-time signon flow; never surface it to the developer
     *    (see DbVault\Http\Controllers\Api\DbSessionController::launch()).
     */
    public function createSession(AccessRequest $accessRequest): DbSession
    {
        $username = $this->generateUsername($accessRequest);
        $database = config('dbvault.target_database');

        // Structural guardrail: this will throw before any SQL is built if
        // the request somehow contains a forbidden privilege.
        $statements = $this->buildGrantSql($accessRequest->grants, $database, $username);

        $minutes = $accessRequest->duration_minutes ?: config('dbvault.default_session_minutes');

        $session = DbSession::create([
            'access_request_id' => $accessRequest->id,
            'mysql_username' => $username,
            'status' => SessionStatus::Pending,
            'max_connections' => config('dbvault.max_user_connections'),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        // When no admin credential is configured, real provisioning is
        // disabled: the session stays Pending (documented stub behaviour) and
        // launch will refuse it. Configure config('dbvault.provisioner') to
        // provision for real.
        if (! $this->canProvision()) {
            return $session;
        }

        $password = $this->generatePassword();

        try {
            $this->runProvisioning($username, $password, $statements);
        } catch (\Throwable $e) {
            // Leave the session Pending and surface the failure; the approval
            // flow can decide how to present it. Never leak the password.
            throw new RuntimeException('Provisioning failed for '.$username.': '.$e->getMessage(), 0, $e);
        }

        $session->forceFill([
            'secret' => $password,
            'status' => SessionStatus::Active,
            'provisioned_at' => now(),
        ])->save();

        return $session;
    }

    /**
     * Whether real provisioning is configured (admin MySQL credentials set).
     */
    public function canProvision(): bool
    {
        return ! empty(config('dbvault.provisioner.admin_username'));
    }

    /**
     * Open an admin PDO connection and run CREATE USER + GRANT statements,
     * substituting the generated password into the CREATE USER placeholder.
     * Executed in order; a failure aborts the rest.
     *
     * @param  list<string>  $statements
     */
    protected function runProvisioning(string $username, string $password, array $statements): void
    {
        $pdo = $this->adminConnection();

        foreach ($statements as $sql) {
            // The CREATE USER statement carries an IDENTIFIED BY '<generated>'
            // placeholder from buildGrantSql(); inject the real password here
            // (quoted by PDO) so it is never present in the returned SQL list
            // that callers might log.
            if (str_contains($sql, "IDENTIFIED BY '<generated>'")) {
                $quoted = $pdo->quote($password);
                $sql = str_replace("IDENTIFIED BY '<generated>'", 'IDENTIFIED BY '.$quoted, $sql);

                if (! config('dbvault.provisioner.require_ssl', false)) {
                    $sql = str_replace(' REQUIRE SSL', '', $sql);
                }
            }

            $pdo->exec($sql);
        }

        $pdo->exec('FLUSH PRIVILEGES');
    }

    protected function adminConnection(): PDO
    {
        $cfg = (array) config('dbvault.provisioner');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 3306),
        );

        return new PDO($dsn, $cfg['admin_username'] ?? null, $cfg['admin_password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    protected function generatePassword(): string
    {
        // 24 URL-safe-ish chars, no quotes/backslashes that could break SQL.
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'AB'), '=');
    }

    /**
     * Drop the temporary MySQL user backing a DbSession and mark it dropped.
     * Called by the scheduled expiry command, on explicit revoke, and on
     * developer logout.
     *
     * TODO:
     *  - $credential = $this->secretsManager->getMasterCredential();
     *  - DROP USER IF EXISTS '<mysql_username>'@'%' on the target RDS instance.
     */
    public function dropSession(DbSession $dbSession): void
    {
        if ($this->canProvision()) {
            try {
                $pdo = $this->adminConnection();
                $pdo->exec(sprintf("DROP USER IF EXISTS '%s'@'%%'", $dbSession->mysql_username));
                $pdo->exec('FLUSH PRIVILEGES');
            } catch (\Throwable $e) {
                // Best-effort: even if the DROP fails (user already gone,
                // instance unreachable), still flip local state so the
                // session is not treated as live. Real deployments should
                // alert on this.
            }
        }

        $dbSession->forceFill([
            'status' => SessionStatus::Dropped,
            'secret' => null, // never retain the password past the session
            'dropped_at' => now(),
        ])->save();
    }

    /**
     * Assemble the CREATE USER + GRANT statements for a request's grant
     * matrix. Structurally refuses to emit SQL if any forbidden privilege
     * (DROP, TRIGGER) is present, regardless of caller intent.
     *
     * @param  Collection<int, RequestGrant>  $grants
     * @return list<string> ordered SQL statements, CREATE USER first
     */
    public function buildGrantSql(Collection $grants, string $targetDatabase, string $mysqlUsername): array
    {
        $forbidden = array_map('strtoupper', config('dbvault.forbidden_privileges'));
        $allowed = array_map('strtoupper', config('dbvault.allowed_privileges'));

        foreach ($grants as $grant) {
            $privilege = strtoupper($grant->privilege instanceof Privilege
                ? $grant->privilege->value
                : (string) $grant->privilege);

            if (in_array($privilege, $forbidden, true)) {
                throw new InvalidArgumentException(
                    "Refusing to build grant SQL: '{$privilege}' is a forbidden privilege and can never be granted."
                );
            }

            if (! in_array($privilege, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Refusing to build grant SQL: '{$privilege}' is not in the allowed privilege set."
                );
            }
        }

        $statements = [
            sprintf(
                "CREATE USER '%s'@'%%' IDENTIFIED BY '<generated>' REQUIRE SSL WITH MAX_USER_CONNECTIONS %d",
                $mysqlUsername,
                (int) config('dbvault.max_user_connections')
            ),
        ];

        // Group grants by (table, column) so each GRANT statement lists all
        // privileges for that object in one shot.
        $grouped = $grants->groupBy(fn (RequestGrant $g) => $g->table_name.'::'.($g->column_name ?? ''));

        foreach ($grouped as $key => $group) {
            [$table, $column] = array_pad(explode('::', $key, 2), 2, null);

            $privileges = $group
                ->map(fn (RequestGrant $g) => strtoupper($g->privilege instanceof Privilege ? $g->privilege->value : (string) $g->privilege))
                ->unique()
                ->implode(', ');

            $object = $column
                ? sprintf('%s (%s)', $this->qualifyTable($targetDatabase, $table), $column)
                : $this->qualifyTable($targetDatabase, $table);

            $statements[] = sprintf(
                "GRANT %s ON %s TO '%s'@'%%'",
                $privileges,
                $object,
                $mysqlUsername
            );
        }

        return $statements;
    }

    /**
     * Generate a unique, per-session MySQL username, e.g. dbv_jdoe_req42.
     */
    protected function generateUsername(AccessRequest $accessRequest): string
    {
        $handle = Str::slug(Str::before($accessRequest->user->email, '@'), '');

        return sprintf(
            '%s%s_req%d',
            config('dbvault.temp_user_prefix'),
            Str::limit($handle, 16, ''),
            $accessRequest->id
        );
    }

    protected function qualifyTable(string $database, string $table): string
    {
        return sprintf('`%s`.`%s`', $database, $table);
    }
}
