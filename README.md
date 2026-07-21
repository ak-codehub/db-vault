# AK-CodeHub/DB-Vault

A self-contained DB access-broker panel (Horizon/Filament-style) that mounts inside any Laravel 11, 12, or 13 application at a configurable domain/path, with its own auth, 2FA, SPA, and JSON API. Developers request a privilege matrix, approvers approve, and a per-request MySQL user is provisioned and auto-expired — **DROP and TRIGGER can never be granted.**

Requires **PHP 8.2–8.5** and **Laravel 11, 12, or 13**.

---

## Quick Start

Four steps get you a working panel. The compiled SPA ships with the package, so there is **no `npm` build step**.

### 1. Install

```bash
composer require ak-codehub/db-vault
```

### 2. Choose the vault's storage database

The vault keeps its own `vault_*` tables in a **separate database** so it never touches your app's schema. Set **one** line in `.env` — the vault reuses your app's existing DB credentials and only changes the database name:

```dotenv
DBVAULT_DB_DATABASE=dbvault
```

(You don't need to create the database yourself — the installer does it for you.)

### 3. Run the installer, then create your admin

```bash
php artisan db-vault:install     # creates the storage DB if missing, migrates, seeds roles
php artisan db-vault:admin       # prompts for name / email / password
```

`db-vault:install` never prompts (safe in CI / piped runs); it only sets up the schema. Creating a human admin is a separate command, `db-vault:admin`, which you can also run later to add more admins. Both are safe to re-run.

> Non-interactive (CI): pass `--name/--email/--password` to `db-vault:admin`, or set `DBVAULT_ADMIN_NAME/EMAIL/PASSWORD` (install then creates the first admin from them automatically).

### 4. Point the vault at the database it brokers

Add these to `.env`, then run `php artisan config:clear`:

```dotenv
# Where the panel mounts (browse to /vault)
DBVAULT_PATH=vault

# The database whose access you are brokering
DBVAULT_TARGET_DATABASE=appdb

# Connection (from config/database.php) used to LIST the target's tables in the
# request form. REQUIRED for the request form to show any tables — without it
# the "Request access" table picker is empty. Usually your host app's default
# MySQL connection.
DBVAULT_INTROSPECTION_CONNECTION=mysql

# Admin MySQL creds used to CREATE USER / GRANT the temporary accounts
DBVAULT_PROVISION_ADMIN_USER=root
DBVAULT_PROVISION_ADMIN_PASSWORD=secret
```

**Done.** Visit `/vault`, log in as your admin, and the request → approve → session flow works. To let approved developers open phpMyAdmin in one click, do the [phpMyAdmin setup](#phpmyadmin-native-under-your-app-url) below.

> **Note — separation of duties:** an approver **cannot approve or reject their own request** (you'll see "You cannot approve or reject your own request"). Use a second user with the `approver` or `admin` role to approve.

### One recommended extra: schedule the expiry sweep

So expired sessions get their MySQL users dropped automatically, add to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dbvault:drop-expired-sessions')->everyFiveMinutes();
```

---

## Advanced Configuration

Everything below is optional — the Quick Start covers a working install. Reach for these when you need phpMyAdmin one-click launch, a non-default database setup, or hardened deployment.

### phpMyAdmin (native, under your app URL)

Clicking **Open phpMyAdmin** on an active session logs the developer straight in as their temporary, scoped MySQL user — password never shown. phpMyAdmin is served **natively under your app's own URL** at `{DBVAULT_PATH}/pma` (e.g. `https://your-app.example.com/vault/pma`) — same origin, same TLS cert, **no separate port and no separate vhost**.

phpMyAdmin runs as its own web-server/PHP-FPM request (it ships its own Composer dependencies, which cannot coexist inside the Laravel process), so it is served by a web-server `location`/`<Directory>` block, not a Laravel route. Only the single-use signon token exchange crosses back into the app. Because phpMyAdmin is a ~50 MB third-party application it is **not bundled** in this package — two commands install and wire it:

**Step 1 — download phpMyAdmin onto this host**

```bash
php artisan dbvault:install-pma
# downloads a pinned, SHA-256-verified phpMyAdmin release into
# storage/app/dbvault-pma and overlays the signon config.inc.php + signon.php.
# --path=/custom/dir to install elsewhere (then set DBVAULT_PMA_PATH to match).
```

**Step 2 — generate the web-server config block and paste it into your vhost**

```bash
php artisan dbvault:pma-vhost           # auto-detects nginx vs Apache + the FPM socket
php artisan dbvault:pma-vhost --server=apache   # force a server
```

This prints a **fully-resolved** block (real install path, your `{vault-path}/pma` prefix, the signon secret, and `PmaAbsoluteUri` derived from `APP_URL`) — nothing is hardcoded and the package never writes to `/etc`. Paste it **inside your app's existing HTTPS `server {}` (nginx) or `<VirtualHost>` (Apache)** block, then reload the web server. The block carries the signon secret to `signon.php` as a `fastcgi_param` (nginx) / `SetEnv` (Apache), since `signon.php` runs outside Laravel and can't read `.env`.

**Step 3 — set the secret in the app `.env`** (must match the value the generated block carries), then `php artisan config:clear`:

```dotenv
# Guards the token-exchange endpoint (the Laravel side of the check). MUST equal
# the DBVAULT_SIGNON_SECRET fastcgi_param/SetEnv in the generated vhost block.
DBVAULT_SIGNON_SECRET=<a long random string>
# Leave DBVAULT_PMA_SIGNON_URL UNSET — launch() then defaults to
# {vault-path}/pma/signon.php automatically. Only set it for an externally
# hosted phpMyAdmin on a different origin.
```

> **Why not `php artisan serve`?** mTLS/native phpMyAdmin need a real web server (nginx/Apache) in front — `artisan serve` is single-process and can't run phpMyAdmin's separate PHP request. Use it only for the panel itself in bare local dev.

**Troubleshooting**
- **Clicking "Open phpMyAdmin" loops back to the same UI** → the exchange returned 403 because `DBVAULT_SIGNON_SECRET` is empty or ≠ the vhost's value. Uncomment/fix it in `.env`, `config:clear`, and confirm it matches the `fastcgi_param`/`SetEnv`. This is the most common cause.
- **404 at `/vault/pma`** → phpMyAdmin isn't installed (re-run `dbvault:install-pma`) or the vhost `alias`/`Alias` points at the wrong path (re-run `dbvault:pma-vhost` to get the correct one). Note `storage/app/dbvault-pma` is git-ignored and cleared by some deploy steps — reinstall after a fresh checkout/deploy.
- **"session no longer valid (HTTP 404/410)"** → the launch token expired (2 min, single-use) or the session isn't active — launch again from the panel.

### Storage database options

`DBVAULT_DB_DATABASE` is normally the only DB var you set — the vault inherits your host's default connection and overrides just the database name. Override more only if the vault DB lives elsewhere or uses a different engine:

```dotenv
# Different engine or location for the vault's own storage:
# DBVAULT_DB_DRIVER=sqlite
# DBVAULT_DB_PATH=/abs/path/dbvault.sqlite        # sqlite only
# DBVAULT_DB_HOST= / _PORT= / _USERNAME= / _PASSWORD=
```

Leave `DBVAULT_DB_DATABASE` **unset** to share the host's default database (legacy shared-schema mode — fine for a quick trial, not recommended for production).

> If you change `DBVAULT_DB_*` **after** installing, the `vault_*` tables were created on the old connection — re-run `php artisan db-vault:install` so they exist on the new one.

### Request-form scoping

Read only when a developer submits a request; safe to set anytime:

```dotenv
# Additional requestable databases beyond the target (comma-separated)
DBVAULT_ALLOWED_DATABASES=appdb
# Connection used to list requestable tables in the form
DBVAULT_INTROSPECTION_CONNECTION=mysql
# Allow / deny lists for tables shown in the request form (trailing-* wildcards)
# DBVAULT_BROWSABLE_TABLES=orders,order_*
# DBVAULT_RESTRICTED_TABLES=users,secrets,billing_*
```

### mTLS, client certificates, subdomain mount

Optional hardening — see [`docs/index.html`](docs/index.html) (self-contained guide) and the scripts in [`docs/scripts/`](docs/scripts/):

| Script | Purpose |
|--------|---------|
| [`setup-nginx-mtls.sh`](docs/scripts/setup-nginx-mtls.sh) | nginx HTTPS + client-cert reverse proxy |
| [`setup-apache-mtls.sh`](docs/scripts/setup-apache-mtls.sh) | apache equivalent |

Related env: `DBVAULT_DOMAIN` (mount on a subdomain instead of a path), `DBVAULT_MIDDLEWARE`, `DBVAULT_MTLS_*`, `DBVAULT_CA_*` (issue client certs — `php artisan dbvault:make-ca` for a local CA). Every knob is documented in `config/dbvault.php`.

### When each `.env` var is read

`db-vault:install` reads only the **storage database** vars. Everything else is read at runtime, so it can be set after install (run `config:clear` after editing):

| Variable(s) | When | Purpose |
|-------------|------|---------|
| `DBVAULT_DB_*` | **before install** | Vault storage DB (migrations land here) |
| `DBVAULT_ADMIN_*` | before install *(only with `--no-interaction`)* | First admin |
| `DBVAULT_PATH` / `DBVAULT_DOMAIN` | anytime | Where the panel mounts |
| `DBVAULT_TARGET_DATABASE` / `DBVAULT_ALLOWED_DATABASES` / `DBVAULT_INTROSPECTION_CONNECTION` | anytime | Request form |
| `DBVAULT_BROWSABLE_TABLES` / `DBVAULT_RESTRICTED_TABLES` | anytime | Table allow/deny |
| `DBVAULT_PROVISION_*` | anytime | Admin MySQL creds (CREATE USER / GRANT at approval) |
| `DBVAULT_SIGNON_SECRET` / `DBVAULT_PMA_PATH` / `DBVAULT_PMA_SIGNON_URL` | anytime | phpMyAdmin (`install-pma` + `pma-vhost`) |
| `DBVAULT_CA_*` / `DBVAULT_MIDDLEWARE` / `DBVAULT_MTLS_*` | anytime | Cert issuance + mTLS |

> Put each `.env` value on its own line with no trailing `# comment` — some dotenv parsers fold the comment into the value.

---

## How it works

The package ships a Vue 3 SPA (`resources/js`, prebuilt to `public/`) backed by a JSON API, wired into the host app by `DbVaultServiceProvider`:

- **Auth** — its own `vault` guard against `vault_users`, fully independent of the host app's users/guards. Email/password login with opt-in 2FA (TOTP + emailed OTP fallback + single-use recovery codes). Session-cookie + CSRF backs the SPA.
- **JSON API** (`routes/api.php`) — `login` / `two-factor-challenge` / `logout` / `me`, `dashboard`, `requests`, `approvals`, `sessions` (list/launch/revoke), `audit`.
- **Schema** — `vault_`-prefixed migrations (namespaced so they never collide with host tables), `RoleSeeder`, and a local-only `DemoSeeder`.
- **Console** — `db-vault:install` (guided setup) and `dbvault:drop-expired-sessions` (expiry sweep).
- **Safety invariant** — `ProvisionerService::buildGrantSql()` structurally refuses to emit SQL containing a forbidden privilege (DROP/TRIGGER) or a non-identifier database/table/column, regardless of what a request row claims.

Provisioning against real RDS and the CloudWatch audit ingest are deliberate stubs (`ProvisionerService`, `SecretsManagerService`) for a later phase.

## Development

The compiled SPA is committed, so consumers never build it. To work on the front end:

```bash
npm install
npm run build      # emits public/app.js + public/app.css (stable, unhashed names)
```

Run the test suite (Testbench host app on in-memory SQLite; covers the auth contract, scoped request submission incl. forbidden-privilege and identifier-injection refusal, install, and the approve/reject flow):

```bash
composer install
vendor/bin/phpunit
```

After editing any script in `docs/scripts/`, regenerate the embedded copies in the HTML guide with `python3 docs/build.py`.
