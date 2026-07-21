<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints a ready-to-paste web-server config block that serves the installed
 * phpMyAdmin natively under "{vault-path}/pma" on the host's own origin/TLS —
 * no separate port or vhost. phpMyAdmin runs as its own web-server/FPM request
 * (its own Composer vendor tree, isolated from Laravel); only the signon token
 * exchange crosses back into the app.
 *
 * The block is GENERATED from this install's real values (phpMyAdmin path,
 * vault path, shared secret, app URL, detected FPM socket) — nothing is
 * hardcoded and the package never writes to /etc. Supports nginx and Apache;
 * the web server is auto-detected unless --server is given.
 */
class PhpMyAdminVhost extends Command
{
    protected $signature = 'dbvault:pma-vhost
        {--server= : Force output for a specific web server: nginx or apache}
        {--socket= : PHP-FPM socket/address to use (e.g. unix:/run/php/php8.3-fpm.sock or 127.0.0.1:9000)}';

    protected $description = 'Print a resolved nginx/Apache config block to serve phpMyAdmin under {vault-path}/pma.';

    public function handle(): int
    {
        $pmaPath = rtrim((string) config('dbvault.pma_path'), '/');
        if ($pmaPath === '' || ! is_dir($pmaPath)) {
            $this->error('phpMyAdmin is not installed. Run `php artisan dbvault:install-pma` first.');
            $this->line('  (looked in '.($pmaPath ?: '[dbvault.pma_path unset]').')');

            return self::FAILURE;
        }

        $vaultPath = trim((string) config('dbvault.path', 'vault'), '/');
        $prefix = '/'.($vaultPath === '' ? 'pma' : $vaultPath.'/pma');       // e.g. /vault/pma
        $secret = (string) config('dbvault.signon_secret');
        $absoluteUri = rtrim((string) config('app.url'), '/').$prefix.'/';   // e.g. https://example.test/vault/pma/

        if ($secret === '') {
            $this->warn('DBVAULT_SIGNON_SECRET is empty — the token-exchange endpoint will reject every request.');
            $this->warn('Set it in .env before serving phpMyAdmin.');
        }

        $server = strtolower((string) ($this->option('server') ?: $this->detectServer()));
        $socket = (string) ($this->option('socket') ?: $this->detectFpmSocket());

        $block = match ($server) {
            'apache', 'apache2', 'httpd' => $this->apacheBlock($pmaPath, $prefix, $secret, $absoluteUri, $socket),
            'nginx' => $this->nginxBlock($pmaPath, $prefix, $secret, $absoluteUri, $socket),
            default => null,
        };

        if ($block === null) {
            $this->error("Could not determine the web server (detected: '{$server}').");
            $this->line('Re-run with --server=nginx or --server=apache.');

            return self::FAILURE;
        }

        $this->line($block);

        return self::SUCCESS;
    }

    /**
     * Best-effort detection of the running web server. Falls back to '' (the
     * caller then asks the user to pass --server).
     */
    private function detectServer(): string
    {
        // Prefer what is actually running, then what is installed.
        foreach ([['nginx', 'nginx'], ['apache2', 'apache'], ['httpd', 'apache']] as [$proc, $name]) {
            if ($this->processRunning($proc) || $this->binaryExists($proc)) {
                return $name;
            }
        }

        return '';
    }

    private function processRunning(string $name): bool
    {
        $out = @shell_exec('pgrep -x '.escapeshellarg($name).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }

    private function binaryExists(string $name): bool
    {
        $out = @shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }

    /**
     * Detect a PHP-FPM unix socket. Falls back to a version-derived guess with
     * a note so the emitted config is still usable.
     */
    private function detectFpmSocket(): string
    {
        $candidates = glob('/run/php/php*-fpm.sock') ?: [];
        $candidates = array_merge($candidates, glob('/var/run/php/php*-fpm.sock') ?: []);
        if ($candidates !== []) {
            // Prefer a socket matching the running PHP minor version if present.
            $ver = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
            foreach ($candidates as $c) {
                if (str_contains($c, $ver)) {
                    return 'unix:'.$c;
                }
            }

            return 'unix:'.$candidates[0];
        }

        // Last-resort guess so the block is complete; user adjusts if wrong.
        return 'unix:/run/php/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-fpm.sock';
    }

    private function nginxBlock(string $pmaPath, string $prefix, string $secret, string $absoluteUri, string $socket): string
    {
        $root = $pmaPath.'/';
        $fastcgiPass = $socket;

        return <<<CONF
# ─────────────────────────────────────────────────────────────────────────
# DB Vault — native phpMyAdmin (nginx). Generated by `dbvault:pma-vhost`.
# Paste INSIDE your app's HTTPS `server { }` block, alongside `location /`.
# Then: nginx -t && systemctl reload nginx
# ─────────────────────────────────────────────────────────────────────────
location ^~ {$prefix}/ {
    alias {$root};
    index index.php;
    try_files \$uri \$uri/ =404;

    # phpMyAdmin's PHP runs as its own FPM request, isolated from Laravel.
    # `^~` above ensures this wins over the app's generic `location ~ \\.php\$`.
    location ~ ^{$prefix}/(?<pma_script>.+\\.php)\$ {
        alias {$root};
        try_files /\$pma_script =404;

        fastcgi_pass {$fastcgiPass};
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$request_filename;
        fastcgi_param SCRIPT_NAME {$prefix}/\$pma_script;

        # signon.php runs outside Laravel — hand it these via fastcgi_param.
        fastcgi_param DBVAULT_SIGNON_SECRET "{$secret}";
        fastcgi_param DBVAULT_PMA_ABSOLUTE_URI "{$absoluteUri}";
    }
}
location = {$prefix} {
    return 301 {$prefix}/;
}
CONF;
    }

    private function apacheBlock(string $pmaPath, string $prefix, string $secret, string $absoluteUri, string $socket): string
    {
        // Apache SetEnv exposes vars to PHP as $_SERVER; php-fpm via
        // mod_proxy_fcgi needs a proxy target. Normalise the socket to a
        // fcgi:// / unix: form Apache understands.
        $fcgiTarget = str_starts_with($socket, 'unix:')
            ? 'unix:'.substr($socket, 5).'|fcgi://localhost'
            : 'fcgi://'.$socket;

        return <<<CONF
# ─────────────────────────────────────────────────────────────────────────
# DB Vault — native phpMyAdmin (Apache 2). Generated by `dbvault:pma-vhost`.
# Paste INSIDE your app's HTTPS <VirtualHost> block, alongside the app's
# DocumentRoot config. Requires mod_alias + mod_setenvif. For PHP:
#   • php-fpm: enable mod_proxy + mod_proxy_fcgi and keep the SetHandler line.
#   • mod_php: comment the SetHandler block (mod_php runs .php automatically).
# Then: apachectl configtest && systemctl reload apache2   (or httpd)
# ─────────────────────────────────────────────────────────────────────────
Alias {$prefix} "{$pmaPath}"

<Directory "{$pmaPath}">
    Require all granted
    DirectoryIndex index.php
    AllowOverride None

    # signon.php runs outside Laravel — expose these to PHP as \$_SERVER.
    SetEnv DBVAULT_SIGNON_SECRET "{$secret}"
    SetEnv DBVAULT_PMA_ABSOLUTE_URI "{$absoluteUri}"

    # php-fpm handler (comment out if using mod_php):
    <FilesMatch "\\.php\$">
        SetHandler "proxy:{$fcgiTarget}"
    </FilesMatch>
</Directory>

# Redirect the bare prefix so phpMyAdmin's relative links resolve.
RedirectMatch 301 "^{$prefix}\$" "{$prefix}/"
CONF;
    }
}
