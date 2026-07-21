# AK-CodeHub/DB-Vault

A self-contained DB access-broker panel (Horizon/Filament-style) that mounts inside any Laravel 11, 12, or 13 application at a configurable domain/path, with its own auth, 2FA, SPA, and JSON API. It brokers temporary, scoped MySQL access: developers request a privilege matrix, approvers approve, and a per-request MySQL user is provisioned and auto-expired — DROP and TRIGGER can never be granted.

Requires **PHP 8.2–8.5** and **Laravel 11, 12, or 13**.

## Setup guide

A full step-by-step setup guide with downloadable scripts is at **[`docs/index.html`](docs/index.html)** — open it in a browser (self-contained, no build/server needed). It covers installing the package, configuring **phpMyAdmin signon** (required), and optional **mTLS** via nginx/apache. The scripts it offers live in [`docs/scripts/`](docs/scripts/):

| Script | Purpose | Needed? |
|--------|---------|---------|
| [`install-phpmyadmin.sh`](docs/scripts/install-phpmyadmin.sh) | Download + configure phpMyAdmin in signon mode | **Required** |
| [`setup-nginx-mtls.sh`](docs/scripts/setup-nginx-mtls.sh) | nginx HTTPS + client-cert reverse proxy | Optional |
| [`setup-apache-mtls.sh`](docs/scripts/setup-apache-mtls.sh) | apache equivalent | Optional |

After editing any script, regenerate the guide's embedded copies with `python3 docs/build.py`.

## What's here

The package ships a Vue 3 SPA (`resources/js`, built to `public/`) backed by a JSON API. This layer wires it into a host app:

- **`DbVaultServiceProvider`** — merges config, registers the self-contained `vault` auth guard + user provider, aliases the package middleware (`vault.auth`, `vault.role`, `vault.gate`, `vault.mtls`), defines the `viewDbVault` gate and the `AccessRequest` policy, loads migrations and the SPA boot view, and mounts the API + SPA at the configured domain/path.
- **JSON API** (`routes/api.php`, `src/Http/Controllers/Api/*`) — auth (`login`, `two-factor-challenge`, `logout`, `me`), `dashboard`, `requests` (list/create/show/cancel), `approvals` (list/approve/reject), `sessions` (list/launch/revoke), and `audit`. Each endpoint matches a helper in `resources/js/api.js` one-to-one.
- **SPA boot** (`routes/web.php`, `SpaController`, `resources/views/app.blade.php`) — a catch-all that emits `window.DbVault = { basePath, apiBase, csrf }` and loads the compiled bundle.
- **Schema** — 11 `vault_`-prefixed migrations (namespaced so they never collide with the host app's tables), `RoleSeeder`, and a local-only `DemoSeeder`.
- **Console** — `db-vault:install` (guided setup) and `dbvault:drop-expired-sessions` (scheduled expiry sweep).

Provisioning against real RDS and the CloudWatch audit ingest remain deliberate stubs (`ProvisionerService`, `SecretsManagerService`) for a later phase; the safety invariant (structural refusal to build grant SQL containing a forbidden privilege) is already enforced.

## Install into a host app

```bash
# 1. Require the package
composer require ak-codehub/db-vault

# 2. Set the VAULT DATABASE in .env FIRST (see "what to configure when" below),
#    then create that empty database in MySQL.

# 3. Build the SPA, THEN install (install publishes the built assets).
npm install && npm run build
php artisan db-vault:install    # publishes config + assets, migrates, seeds roles, creates the first admin
```

### What to configure when

`db-vault:install` runs in order: **publish assets/config → migrate → seed roles → create admin**. Only the *vault storage database* is read during that run — everything else is read later, at runtime, so it can be set **after** install (run `php artisan config:clear` after editing `.env`).

| `.env` variable(s) | Set it… | Why |
|--------------------|---------|-----|
| **`DBVAULT_DB_DATABASE`** (vault storage DB) | **BEFORE install** | `db-vault:install` migrates the `vault_*` tables into this connection. The vault connection **inherits your host app's default DB credentials** (driver/host/user/password) and only overrides the database name — so usually this is the *only* var you set. Leave it unset to share the host's default DB (shared-schema). |
| **`DBVAULT_ADMIN_NAME/EMAIL/PASSWORD`** | Before install *(only if `--no-interaction`)* | Used to create the first admin. Interactive mode prompts for these instead. |
| `DBVAULT_PATH` / `DBVAULT_DOMAIN` | Anytime | Where the panel mounts (runtime routing). |
| `DBVAULT_TARGET_DATABASE`, `DBVAULT_ALLOWED_DATABASES`, `DBVAULT_INTROSPECTION_CONNECTION` | Anytime (after is fine) | Read only when a user submits an access request. |
| `DBVAULT_BROWSABLE_TABLES` / `DBVAULT_RESTRICTED_TABLES` | Anytime | Request-form table allow/deny lists (runtime). |
| `DBVAULT_PROVISION_*` | Anytime | Admin MySQL creds, read only at approval time (CREATE USER/GRANT). |
| `DBVAULT_PMA_SIGNON_URL`, `DBVAULT_SIGNON_SECRET` | Anytime | phpMyAdmin launch (runtime). |
| `DBVAULT_CA_*`, `DBVAULT_MIDDLEWARE`, `DBVAULT_MTLS_*` | Anytime | Cert issuance + mTLS enforcement (runtime). |

```dotenv
# --- Set BEFORE db-vault:install (vault's own, isolated storage DB) ---
# Usually just this one line: the connection inherits your host DB_* creds
# and only points at a different database. Create this empty DB first.
DBVAULT_DB_DATABASE=dbvault
# Optional overrides (only if the vault DB uses DIFFERENT creds/host than your app):
#   DBVAULT_DB_DRIVER=mysql        # or sqlite / pgsql
#   DBVAULT_DB_PATH=/abs/dbvault.sqlite   # sqlite only
#   DBVAULT_DB_HOST= / _PORT= / _USERNAME= / _PASSWORD=

# --- Can be set anytime after install ---
# NOTE: put each value on its own line with NO trailing "# comment" — some
# dotenv setups treat the comment as part of the value. Replace the sample
# values below with your real ones.

# Where the panel mounts (or set DBVAULT_DOMAIN for a subdomain)
DBVAULT_PATH=vault
# The database whose access is brokered
DBVAULT_TARGET_DATABASE=appdb
# Additional requestable databases (real names, comma-separated) — or omit
DBVAULT_ALLOWED_DATABASES=appdb
# Connection used to list target tables in the request form
DBVAULT_INTROSPECTION_CONNECTION=mysql
# Admin creds used to CREATE USER / GRANT on the target at approval time
DBVAULT_PROVISION_ADMIN_USER=root
DBVAULT_PROVISION_ADMIN_PASSWORD=secret
# phpMyAdmin signon (must match the secret in the phpMyAdmin signon script)
DBVAULT_PMA_SIGNON_URL=http://pma-host:8080/signon.php
DBVAULT_SIGNON_SECRET=change-me
```

> **If you set `DBVAULT_DB_*` *after* install**, the `vault_*` tables were already created on the old connection — re-run `php artisan db-vault:install` (or `migrate`) so they exist on the new one.

See `config/dbvault.php` for every option, and the full setup guide at **[`docs/index.html`](docs/index.html)** (phpMyAdmin + optional mTLS, with downloadable scripts).

Schedule the expiry sweep in the host app's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('dbvault:drop-expired-sessions')->everyFiveMinutes();
```

## Auth model

The vault authenticates on its **own** `vault` guard against `vault_users` — completely independent of the host app's users/guards. Login is local email/password with opt-in second factor: TOTP (authenticator app) as the primary channel, an emailed OTP as a fallback, and single-use recovery codes. Session-cookie + CSRF auth backs the SPA (no Inertia).

## Front-end build

```bash
npm install
npm run build      # emits public/app.js + public/app.css (stable, unhashed names)
```

`php artisan vendor:publish --tag=db-vault-assets` copies the build into the host app's `public/vendor/db-vault`.

## Tests

```bash
composer install
vendor/bin/phpunit
```

Feature tests run against a Testbench host app on in-memory SQLite and cover the auth contract, scoped request submission (including forbidden-privilege refusal), and the approve/reject flow.
