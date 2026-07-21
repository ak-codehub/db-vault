<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Downloads a pinned phpMyAdmin release onto the host system and overlays the
 * package's signon configuration, so phpMyAdmin can be served natively under
 * "{vault-path}/pma" (generate the web-server config with `dbvault:pma-vhost`).
 *
 * phpMyAdmin is a ~50 MB third-party application with its own Composer vendor
 * tree — it is NOT bundled in this package (which would bloat every install and
 * force a re-vendor on every phpMyAdmin security release). Instead the package
 * ships only the two files it customises (config.inc.php, signon.php) and this
 * command fetches the rest, verifying the official SHA-256 before extracting.
 *
 * The download is a fixed, checksum-pinned version → fully reproducible. Bump
 * VERSION/CHECKSUM here to move to a newer phpMyAdmin.
 */
class InstallPhpMyAdmin extends Command
{
    protected $signature = 'dbvault:install-pma
        {--path= : Directory to install phpMyAdmin into (default storage/app/dbvault-pma)}
        {--force : Overwrite an existing installation in the target directory}
        {--keep-archive : Keep the downloaded .tar.gz after extraction}';

    protected $description = 'Download phpMyAdmin and overlay the DB Vault signon config for native serving.';

    /** Pinned phpMyAdmin release. Bump both together (see the .sha256 file on files.phpmyadmin.net). */
    private const VERSION = '5.2.2';

    private const CHECKSUM = '3b2017f5374216a58b3a0ae65112e76716212f3a57c8fac383029e98f6cec451';

    private function archiveName(): string
    {
        return 'phpMyAdmin-'.self::VERSION.'-english.tar.gz';
    }

    private function downloadUrl(): string
    {
        return 'https://files.phpmyadmin.net/phpMyAdmin/'.self::VERSION.'/'.$this->archiveName();
    }

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('path') ?: storage_path('app/dbvault-pma')), '/');

        if (is_dir($dir) && (new \FilesystemIterator($dir))->valid()) {
            if (! $this->option('force')) {
                $this->error("Target {$dir} already exists and is not empty. Re-run with --force to overwrite.");

                return self::FAILURE;
            }
            $this->warn("Overwriting existing installation at {$dir} (--force).");
            $this->deleteTree($dir);
        }

        $parent = dirname($dir);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new RuntimeException("Could not create {$parent}");
        }

        // 1. Download.
        $tmp = tempnam(sys_get_temp_dir(), 'dbv-pma-').'.tar.gz';
        $this->info('Downloading phpMyAdmin '.self::VERSION.' …');
        $this->download($this->downloadUrl(), $tmp);

        // 2. Verify checksum before touching the filesystem further.
        $this->info('Verifying SHA-256 …');
        $actual = hash_file('sha256', $tmp);
        if (! hash_equals(self::CHECKSUM, (string) $actual)) {
            @unlink($tmp);
            $this->error('Checksum mismatch — refusing to install.');
            $this->line("  expected: ".self::CHECKSUM);
            $this->line("  actual:   {$actual}");

            return self::FAILURE;
        }

        // 3. Extract into a staging dir, then move the single top-level folder
        // the tarball contains (phpMyAdmin-<ver>-english/) to $dir.
        $this->info('Extracting …');
        $staging = $parent.'/.dbv-pma-staging-'.getmypid();
        $this->deleteTree($staging);
        if (! mkdir($staging, 0755, true)) {
            throw new RuntimeException("Could not create staging dir {$staging}");
        }

        $phar = new \PharData($tmp);
        $phar->extractTo($staging, null, true);

        $extracted = $staging.'/phpMyAdmin-'.self::VERSION.'-english';
        if (! is_dir($extracted)) {
            // Fall back to whatever single directory landed in staging.
            $dirs = glob($staging.'/*', GLOB_ONLYDIR) ?: [];
            $extracted = $dirs[0] ?? '';
        }
        if ($extracted === '' || ! is_dir($extracted)) {
            $this->deleteTree($staging);
            @unlink($tmp);
            throw new RuntimeException('Unexpected archive layout — could not locate extracted phpMyAdmin.');
        }

        if (! rename($extracted, $dir)) {
            // Cross-device rename can fail; copy then clean up.
            $this->copyTree($extracted, $dir);
        }
        $this->deleteTree($staging);

        // 4. Overlay our customised config + signon bridge.
        $this->info('Applying DB Vault signon config …');
        $stubs = dirname(__DIR__, 3).'/phpmyadmin-stubs';
        foreach (['config.inc.php', 'signon.php'] as $file) {
            if (! copy($stubs.'/'.$file, $dir.'/'.$file)) {
                throw new RuntimeException("Failed to copy {$file} into {$dir}");
            }
        }

        // 5. Cleanup + guidance.
        if (! $this->option('keep-archive')) {
            @unlink($tmp);
        } else {
            $this->line("Archive kept at {$tmp}");
        }

        $this->newLine();
        $this->info("phpMyAdmin installed at: {$dir}");
        $this->line('If you used a custom --path, set it in the host app .env:');
        $this->line("  DBVAULT_PMA_PATH={$dir}");
        $this->newLine();
        $this->line('Now generate a web-server config block for your server (nginx or Apache):');
        $this->line('  php artisan dbvault:pma-vhost');
        $this->line('Paste the printed block into your app vhost, then reload the web server.');

        return self::SUCCESS;
    }

    /**
     * Stream a URL to a local file. Uses curl when available (progress + TLS),
     * else a stream copy.
     */
    private function download(string $url, string $dest): void
    {
        if (function_exists('curl_init')) {
            $fh = fopen($dest, 'wb');
            if ($fh === false) {
                throw new RuntimeException("Cannot open {$dest} for writing");
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_USERAGENT => 'db-vault/install-pma',
            ]);
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fh);

            if ($ok === false || $code >= 400) {
                @unlink($dest);
                throw new RuntimeException("Download failed (HTTP {$code}): ".($err ?: $url));
            }

            return;
        }

        $src = @fopen($url, 'rb');
        if ($src === false) {
            throw new RuntimeException("Could not open {$url}");
        }
        $dst = fopen($dest, 'wb');
        if ($dst === false) {
            fclose($src);
            throw new RuntimeException("Cannot open {$dest} for writing");
        }
        stream_copy_to_stream($src, $dst);
        fclose($src);
        fclose($dst);
    }

    private function deleteTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    private function copyTree(string $from, string $to): void
    {
        if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
            throw new RuntimeException("Could not create {$to}");
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $to.'/'.$it->getSubPathname();
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
