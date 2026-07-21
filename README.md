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

### 3. Run the installer

```bash
php artisan db-vault:install
```

This creates the storage database if missing, runs the vault migrations, seeds roles (developer / approver / admin / auditor), and prompts you to create the first admin. It is safe to re-run.

> Non-interactive (CI): set `DBVAULT_ADMIN_NAME`, `DBVAULT_ADMIN_EMAIL`, `DBVAULT_ADMIN_PASSWORD` in `.env` and run `php artisan db-vault:install --no-interaction`.

### 4. Point the vault at the database it brokers

Add these to `.env`, then run `php artisan config:clear`:

```dotenv
# Where the panel mounts (browse to /vault)
DBVAULT_PATH=vault

# The database whose access you are brokering
DBVAULT_TARGET_DATABASE=appdb

# Admin MySQL creds used to CREATE USER / GRANT the temporary accounts
DBVAULT_PROVISION_ADMIN_USER=root
DBVAULT_PROVISION_ADMIN_PASSWORD=secret
```

**Done.** Visit `/vault`, log in as your admin, and the request → approve → session flow works. To let approved developers open phpMyAdmin in one click, do the [phpMyAdmin signon](#phpmyadmin-signon) setup below.

### One recommended extra: schedule the expiry sweep

So expired sessions get their MySQL users dropped automatically, add to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dbvault:drop-expired-sessions')->everyFiveMinutes();
```

---

## Advanced Configuration

Everything below is optional — the Quick Start covers a working install. Reach for these when you need phpMyAdmin one-click launch, a non-default database setup, or hardened deployment.

### phpMyAdmin signon

Clicking **Open phpMyAdmin** on an active session logs the developer straight in as their temporary, scoped MySQL user — password never shown. The `install-phpmyadmin.sh` script (in [`docs/scripts/`](docs/scripts/)) downloads phpMyAdmin and writes the two files that make this work (`config.inc.php` in signon mode + a `signon.php` bridge).

**The one thing to get right:** there are **two different URLs** for two different services. Mixing them up is the most common failure.

| URL | Direction | Points at | Set via |
|-----|-----------|-----------|---------|
| **Exchange URL** | phpMyAdmin → vault (server-to-server) | your **Laravel app** | `VAULT_EXCHANGE_URL` (baked into `signon.php`) |
| **Signon URL** | browser → phpMyAdmin | **phpMyAdmin** | `DBVAULT_PMA_SIGNON_URL` (vault `.env`) |

**Install** — point the exchange at your app's **real, stable URL** (an nginx/apache vhost). Do **not** use a `php artisan serve` port: that process reads `.env` once at boot and serves a stale signon secret, causing a 403 at exchange.

```bash
sudo VAULT_EXCHANGE_URL="https://your-app.example.com/vault/api/sessions/exchange" \
     VAULT_SIGNON_SECRET="$(openssl rand -hex 24)" \
     ./install-phpmyadmin.sh
```

**Then** set both of these in the vault app's `.env` and run `php artisan config:clear`:

```dotenv
# must byte-for-byte equal VAULT_SIGNON_SECRET the script printed
DBVAULT_SIGNON_SECRET=<the secret the script printed>
# where the BROWSER opens phpMyAdmin (wherever you serve it)
DBVAULT_PMA_SIGNON_URL=https://pma.example.com/signon.php
```

**Serving phpMyAdmin behind nginx** (instead of the built-in dev server): run the installer with `PMA_PORT=0` (skips `php -S`), point an nginx vhost with PHP-FPM at `PMA_DIR` (default `/var/www/phpmyadmin`), and set `DBVAULT_PMA_SIGNON_URL` to that vhost's `…/signon.php`.

**Local dev with a self-signed cert** (e.g. `*.local`): add two **local-only** flags so `signon.php`'s curl reaches the vault without a public cert or DNS entry (they are omitted from the generated file unless set — never use them in production):

```bash
sudo VAULT_EXCHANGE_URL="https://oms.local/vault/api/sessions/exchange" \
     VAULT_SIGNON_SECRET="$(openssl rand -hex 24)" \
     VAULT_INSECURE_TLS=1 \
     VAULT_RESOLVE="oms.local:443:127.0.0.1" \
     ./install-phpmyadmin.sh
```

**Troubleshooting** — the generated `signon.php` reports the actual cause:
- *"Could not reach the vault…"* → wrong `VAULT_EXCHANGE_URL`, DNS, or TLS.
- *"Signon secret mismatch…"* → `VAULT_SIGNON_SECRET` ≠ the app's `DBVAULT_SIGNON_SECRET`; fix `.env`, then `config:clear` **and restart the web process** (a running `artisan serve` holds a stale `.env`).
- *"session no longer valid (HTTP 404/410)"* → the launch token expired (2 min, single-use) or the session isn't active — launch again from the panel.

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
| `DBVAULT_PMA_SIGNON_URL` / `DBVAULT_SIGNON_SECRET` | anytime | phpMyAdmin launch |
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
