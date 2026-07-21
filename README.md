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
composer require ak-codehub/db-vault
php artisan db-vault:install    # publishes config + assets, migrates, seeds roles, creates the first admin
```

Configure the install via `.env` (see `config/dbvault.php` for every option):

```dotenv
DBVAULT_PATH=vault                 # mount at appname.com/vault (or set DBVAULT_DOMAIN for a subdomain)
DBVAULT_TARGET_DATABASE=appdb
DBVAULT_MASTER_SECRET=prod/db/master
DBVAULT_RDS_HOST=...
DBVAULT_SERVER_LABEL="prod · web-01"
DBVAULT_PMA_SIGNON_URL=https://pma.internal/vault_signon.php
```

Then front it with the mTLS nginx vhost from `Phase-0-Infra-Runbook.md` and, to enforce the client-certificate leg, prepend `vault.mtls` to `config('dbvault.middleware')`.

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
