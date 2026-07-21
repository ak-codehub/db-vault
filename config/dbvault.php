<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Mount Point
    |--------------------------------------------------------------------------
    |
    | Where the vault's SPA + JSON API is mounted inside the host application.
    | `domain` is nullable: leave it null to mount at a path on the host's
    | existing domain (e.g. appname.com/vault), or set it to mount on its own
    | subdomain (e.g. vault.appname.com), in which case `path` is typically
    | left as an empty prefix by setting it to null/''.
    |
    */
    'domain' => env('DBVAULT_DOMAIN'),
    'path' => env('DBVAULT_PATH', 'vault'),

    /*
    |--------------------------------------------------------------------------
    | Route Middleware / Guard
    |--------------------------------------------------------------------------
    |
    | `middleware` is applied to the entire mounted route group (both the SPA
    | boot route and the JSON API). `guard` is the name of the vault's own,
    | self-contained auth guard (see DbVaultServiceProvider::registerGuard()) -
    | distinct from the host application's own guards/users.
    |
    */
    // Middleware wrapping the whole vault (SPA + API). 'web' provides the
    // session + CSRF the SPA login needs — keep it. To enforce mTLS, prepend
    // 'vault.mtls' (only behind a reverse proxy that terminates client certs,
    // else every request 403s). Env override is a comma list, e.g.
    // DBVAULT_MIDDLEWARE="vault.mtls,web".
    'middleware' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('DBVAULT_MIDDLEWARE', 'web')
    )))),
    'guard' => 'vault',

    /*
    |--------------------------------------------------------------------------
    | Target Database
    |--------------------------------------------------------------------------
    |
    | The production MySQL schema that access requests are brokered against.
    | This is distinct from the vault's own application tables (vault_*),
    | which live in the host app's own default database connection.
    |
    */
    'target_database' => env('DBVAULT_TARGET_DATABASE', 'appdb'),

    /*
    |--------------------------------------------------------------------------
    | Schema Introspection Connection
    |--------------------------------------------------------------------------
    |
    | Optional Laravel database connection name (config('database.connections'))
    | the request form introspects to list requestable tables. Leave null to
    | fall back to the static `browsable_tables` catalog below — the right
    | default while real RDS access is brokered by the (stubbed)
    | ProvisionerService. Point it at a read-only replica connection to offer
    | a live table pick-list. Introspection is read-only (INFORMATION_SCHEMA).
    |
    */
    'introspection_connection' => env('DBVAULT_INTROSPECTION_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Vault Storage Connection
    |--------------------------------------------------------------------------
    |
    | The database the vault stores its OWN bookkeeping in (vault_users,
    | vault_access_requests, and every other vault_* table). This is kept
    | separate from the host application's default connection on purpose: the
    | vault must never share a schema with — or be able to interfere with —
    | the production application it is bolted onto, nor with the target
    | database whose access it brokers.
    |
    | `connection` is the connection NAME the vault's models and migrations
    | bind to. The package registers this connection for you at boot from the
    | `connections.<name>` definition below (built from DBVAULT_DB_* env
    | vars), so the host application needs no changes to its own
    | config/database.php. Point DBVAULT_DB_* at a dedicated database.
    |
    | Leave DBVAULT_DB_DATABASE unset to fall back to the host's default
    | connection (the legacy shared-schema behaviour) — useful for a quick
    | single-database trial, but not recommended for production.
    |
    */
    // Use the dedicated 'dbvault' connection whenever the vault DB is
    // configured — either a database name (DBVAULT_DB_DATABASE) or a SQLite
    // file path (DBVAULT_DB_PATH). Otherwise fall back to the host's default
    // connection (legacy shared-schema behaviour).
    'connection' => (env('DBVAULT_DB_DATABASE') || env('DBVAULT_DB_PATH'))
        ? 'dbvault'
        : env('DB_CONNECTION', 'mysql'),

    // The dedicated 'dbvault' connection. By default it INHERITS the host
    // app's existing default connection (driver, host, port, username,
    // password, socket, charset, ...) and only overrides the database it
    // points at — so in the common case you set just ONE thing:
    //
    //     DBVAULT_DB_DATABASE=dbvault      # MySQL/Postgres: separate database
    //   or
    //     DBVAULT_DB_DRIVER=sqlite
    //     DBVAULT_DB_PATH=/abs/path/dbvault.sqlite
    //
    // Every DBVAULT_DB_* var below is an optional override; unset ones reuse
    // the host's DB_* credentials, which already work. This is resolved in the
    // service provider (registerDatabaseConnection) so it can read the host's
    // live default-connection config at boot.
    'connections' => [
        'dbvault' => [
            // Marker consumed by the provider; the real definition is built
            // there by merging the host default connection with these
            // overrides. Kept here so config:cache captures the env values.
            'driver' => env('DBVAULT_DB_DRIVER'),        // null => inherit host default's driver
            'database' => env('DBVAULT_DB_DATABASE'),    // MySQL/pgsql db name
            'path' => env('DBVAULT_DB_PATH'),            // sqlite file path
            'host' => env('DBVAULT_DB_HOST'),
            'port' => env('DBVAULT_DB_PORT'),
            'username' => env('DBVAULT_DB_USERNAME'),
            'password' => env('DBVAULT_DB_PASSWORD'),
            'unix_socket' => env('DBVAULT_DB_SOCKET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Master Secret / AWS
    |--------------------------------------------------------------------------
    |
    | AWS Secrets Manager path holding the MASTER MySQL credential. The
    | Provisioner reads this secret to authenticate to the RDS instance and
    | run CREATE USER / GRANT statements on behalf of approved requests. See
    | DbVault\Services\SecretsManagerService. No static AWS keys are
    | configured here on purpose - an instance/task IAM role is expected to
    | grant secretsmanager:GetSecretValue.
    |
    */
    'master_secret' => env('DBVAULT_MASTER_SECRET', 'prod/db/master'),
    'aws_region' => env('AWS_REGION', 'us-east-1'),

    /*
    |--------------------------------------------------------------------------
    | RDS Connection
    |--------------------------------------------------------------------------
    |
    | The target RDS MySQL instance that temporary, scoped users are
    | provisioned on by the Provisioner.
    |
    */
    'rds_host' => env('DBVAULT_RDS_HOST'),
    'rds_port' => (int) env('DBVAULT_RDS_PORT', 3306),

    /*
    | Admin MySQL credential the Provisioner authenticates with to run
    | CREATE USER / GRANT / DROP USER on the target instance. In production
    | this comes from Secrets Manager (see `master_secret` +
    | SecretsManagerService); for direct-credential deployments set these env
    | vars. When `admin_username` is empty, real provisioning is disabled and
    | sessions stay Pending (the legacy stub behaviour).
    */
    'provisioner' => [
        'host' => env('DBVAULT_PROVISION_HOST', env('DBVAULT_RDS_HOST', '127.0.0.1')),
        'port' => (int) env('DBVAULT_PROVISION_PORT', env('DBVAULT_RDS_PORT', 3306)),
        'admin_username' => env('DBVAULT_PROVISION_ADMIN_USER'),
        'admin_password' => env('DBVAULT_PROVISION_ADMIN_PASSWORD'),
        // Require issued temp users to connect over SSL (REQUIRE SSL). Off for
        // local dev MySQL that has no TLS configured.
        'require_ssl' => (bool) env('DBVAULT_PROVISION_REQUIRE_SSL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary User Provisioning
    |--------------------------------------------------------------------------
    |
    | - temp_user_prefix: prefix applied to every generated MySQL username,
    |   e.g. "dbv_" + "jdoe" + "_req42" => dbv_jdoe_req42.
    | - default_session_minutes: lifetime applied when a request doesn't
    |   specify its own duration.
    | - max_user_connections: MAX_USER_CONNECTIONS enforced on every
    |   temporary MySQL user created by the Provisioner.
    |
    */
    'temp_user_prefix' => env('DBVAULT_TEMP_USER_PREFIX', 'dbv_'),
    'default_session_minutes' => (int) env('DBVAULT_DEFAULT_SESSION_MINUTES', 60),
    'max_user_connections' => (int) env('DBVAULT_MAX_USER_CONNECTIONS', 3),

    /*
    |--------------------------------------------------------------------------
    | Privilege Matrix
    |--------------------------------------------------------------------------
    |
    | Privileges that may ever be granted to a temporary session user, and
    | the privileges that must NEVER be granted regardless of what a request
    | asks for. Enforced structurally in
    | DbVault\Services\ProvisionerService::buildGrantSql() and validated in
    | DbVault\Http\Requests\StoreAccessRequestRequest.
    |
    */
    'allowed_privileges' => [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'ALTER',
        'INDEX',
    ],

    'forbidden_privileges' => [
        'DROP',
        'TRIGGER',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Form Options
    |--------------------------------------------------------------------------
    |
    | - allowed_databases: additional databases (beyond target_database)
    |   developers may request access to, e.g. a read replica.
    | - available_durations: the fixed set of session lengths (minutes) the
    |   request form and its validation accept.
    | - browsable_tables: an ALLOWLIST of tables the request form may show.
    |   * Empty (the default) or "*"  -> show ALL tables in the selected
    |     database (via live introspection), i.e. no allowlist restriction.
    |   * A list (e.g. "orders,order_*") -> show ONLY tables matching an
    |     entry, even when live introspection is on. Also serves as the
    |     catalog when no introspection connection is configured.
    |   Case-insensitive; supports trailing-'*' prefix wildcards.
    | - restricted_tables: a DENYLIST applied AFTER the allowlist, to HIDE
    |   sensitive tables (auth, secrets, billing internals) from the request
    |   form regardless of source. Case-insensitive; trailing-'*' wildcards.
    |
    | Pipeline:  discovered tables -> browsable allowlist -> restricted denylist
    |
    */
    'allowed_databases' => array_values(array_filter(explode(
        ',',
        (string) env('DBVAULT_ALLOWED_DATABASES', '')
    ))),

    'available_durations' => [15, 30, 60, 120, 240],

    // Empty by default => show every table in the target database.
    'browsable_tables' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('DBVAULT_BROWSABLE_TABLES', '')
    )))),

    'restricted_tables' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('DBVAULT_RESTRICTED_TABLES', '')
    )))),

    /*
    |--------------------------------------------------------------------------
    | Query Audit
    |--------------------------------------------------------------------------
    |
    | CloudWatch Logs group the RDS MariaDB Audit Plugin ships query audit
    | events to. An ingest job (out of scope here) tails this log group and
    | binds individual queries back to the vault_db_sessions /
    | vault_access_requests that produced them.
    |
    */
    'audit_log_group' => env('DBVAULT_AUDIT_LOG_GROUP', '/aws/rds/instance/RDS_ID/audit'),

    /*
    |--------------------------------------------------------------------------
    | mTLS
    |--------------------------------------------------------------------------
    |
    | mTLS is terminated at the reverse proxy (nginx). It forwards the
    | verification result and the client certificate's distinguished name via
    | these headers. See DbVault\Http\Middleware\TrustClientCertificate.
    |
    */
    'mtls_dn_header' => env('DBVAULT_MTLS_DN_HEADER', 'X-Client-Cert-DN'),
    'mtls_verify_header' => env('DBVAULT_MTLS_VERIFY_HEADER', 'X-Client-Verify'),
    // Optional fingerprint (SHA-1/256) header nginx forwards. When present,
    // device matching prefers it over the DN.
    'mtls_fingerprint_header' => env('DBVAULT_MTLS_FINGERPRINT_HEADER', 'X-Client-Cert-Fingerprint'),

    // When true, a verified cert must additionally match an ENROLLED,
    // non-revoked vault_devices row for the authenticating user. When false
    // (default), any proxy-verified cert is accepted (device rows are then
    // informational only). Turn on once devices are enrolled.
    'mtls_require_enrolled_device' => (bool) env('DBVAULT_MTLS_REQUIRE_ENROLLED_DEVICE', false),

    /*
    |--------------------------------------------------------------------------
    | Client-Certificate Authority (CA)
    |--------------------------------------------------------------------------
    |
    | When a CA cert+key are configured, the vault can ISSUE client
    | certificates itself (Devices → Issue certificate): it generates a
    | keypair, signs a short-lived client cert with this CA, enrols the device
    | (DN + fingerprint), and hands back a password-protected .p12 for the
    | user to install. The same CA must be the one nginx trusts for the mTLS
    | `ssl_client_certificate` bundle, so certs it issues are accepted.
    |
    | Generate a local CA for testing with:  php artisan dbvault:make-ca
    | Leave the paths unset to disable issuance (the manual DN/fingerprint
    | enrolment path still works for externally-issued certs).
    |
    */
    'ca' => [
        'cert_path' => env('DBVAULT_CA_CERT'),   // PEM CA certificate
        'key_path' => env('DBVAULT_CA_KEY'),     // PEM CA private key
        'key_passphrase' => env('DBVAULT_CA_KEY_PASSPHRASE'),
        'client_cert_days' => (int) env('DBVAULT_CLIENT_CERT_DAYS', 365),
        // Subject fields applied to issued client certs; CN is set per-user.
        'subject' => [
            'organizationName' => env('DBVAULT_CERT_ORG', 'DB Vault'),
            'organizationalUnitName' => env('DBVAULT_CERT_OU', 'devices'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | phpMyAdmin One-Time Signon
    |--------------------------------------------------------------------------
    |
    | Endpoint developers are redirected to with a single-use token. No MySQL
    | credential is ever displayed to the developer. See
    | DbVault\Http\Controllers\Api\DbSessionController::launch().
    |
    */
    'pma_signon_url' => env('DBVAULT_PMA_SIGNON_URL'),

    // Shared secret the phpMyAdmin signon script presents (X-DbVault-Signon
    // header) to the token-exchange endpoint. Only used by the LEGACY external
    // signon.php bridge (separate phpMyAdmin process over HTTP). When phpMyAdmin
    // is served natively via the built-in proxy (see `pma_path` below) the
    // exchange happens in-process and no shared secret is needed.
    'signon_secret' => env('DBVAULT_SIGNON_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Native phpMyAdmin
    |--------------------------------------------------------------------------
    |
    | phpMyAdmin is served natively under `{path}/pma` (e.g.
    | https://host/vault/pma) on the host's own origin/TLS — no separate port,
    | no separate vhost. It runs as its OWN web-server/FPM request (it ships its
    | own Composer vendor tree, which cannot coexist in the Laravel process), so
    | it is served by a web-server location block, NOT a Laravel route. An nginx
    | template ships at phpmyadmin-stubs/deploy/nginx-pma.conf.
    |
    | phpMyAdmin is not bundled in this package (it is a ~50 MB third-party app);
    | install it onto this host with `php artisan dbvault:install-pma`, which
    | downloads a pinned, checksum-verified release into `pma_path` and overlays
    | the packaged signon config. `pma_path` defaults to storage/app/dbvault-pma.
    |
    | `pma_signon_url` (above) may be left unset: DbSessionController::launch()
    | then defaults to the relative "{path}/pma/signon.php". Set it to an
    | absolute URL only for an externally-hosted phpMyAdmin.
    |
    */
    'pma_path' => env('DBVAULT_PMA_PATH', storage_path('app/dbvault-pma')),

    // Whether launch() should default pma_signon_url to the native
    // "{path}/pma/signon.php" when it is otherwise unset. Kept as a flag so an
    // install with no phpMyAdmin can disable the phpMyAdmin launch affordance.
    'pma_proxy_enabled' => env('DBVAULT_PMA_PROXY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Server Label
    |--------------------------------------------------------------------------
    |
    | Every install is configured per host application. This label is shown
    | in the UI so operators can tell installs/environments apart.
    |
    */
    'server_label' => env('DBVAULT_SERVER_LABEL', 'unlabeled'),

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Authentication
    |--------------------------------------------------------------------------
    |
    | - issuer: the label shown in authenticator apps next to the account.
    | - email_otp_ttl: minutes an emailed one-time code remains valid.
    |
    */
    'two_factor' => [
        'issuer' => env('DBVAULT_2FA_ISSUER', 'DB Vault'),
        'email_otp_ttl' => (int) env('DBVAULT_EMAIL_OTP_TTL', 10),
    ],

];
