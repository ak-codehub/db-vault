<?php

declare(strict_types=1);

namespace DbVault\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Generates a self-signed Certificate Authority (cert + key) the vault can use
 * to issue client certificates for mTLS device enrolment. Intended for local
 * dev and internal deployments that don't already run a CA.
 *
 * The same CA cert must be handed to nginx as `ssl_client_certificate` so the
 * client certs the vault issues are trusted at the TLS handshake.
 *
 * After running, point the vault at the files:
 *   DBVAULT_CA_CERT=storage/app/dbvault-ca/ca.crt
 *   DBVAULT_CA_KEY=storage/app/dbvault-ca/ca.key
 */
class MakeCertificateAuthority extends Command
{
    protected $signature = 'dbvault:make-ca
        {--path= : Directory to write ca.crt/ca.key into (default storage/app/dbvault-ca)}
        {--cn=DB Vault Device CA : CA common name}
        {--days=3650 : CA validity in days}
        {--force : Overwrite an existing CA in the target directory}';

    protected $description = 'Generate a self-signed CA for issuing client certificates.';

    public function handle(): int
    {
        $dir = $this->option('path') ?: storage_path('app/dbvault-ca');
        $certPath = $dir.'/ca.crt';
        $keyPath = $dir.'/ca.key';

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        if ((is_file($certPath) || is_file($keyPath)) && ! $this->option('force')) {
            $this->components->error("A CA already exists in {$dir}. Use --force to overwrite.");

            return self::FAILURE;
        }

        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Failed to generate CA key: '.openssl_error_string());
        }

        $dn = ['organizationName' => 'DB Vault', 'commonName' => (string) $this->option('cn')];

        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('Failed to build CA CSR: '.openssl_error_string());
        }

        // Self-sign (CA signs its own CSR) as a v3 CA certificate.
        $cert = openssl_csr_sign($csr, null, $key, (int) $this->option('days'), ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        if ($cert === false) {
            throw new RuntimeException('Failed to self-sign CA: '.openssl_error_string());
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);
        chmod($keyPath, 0600);

        $this->components->info('Generated CA');
        $this->components->twoColumnDetail('Certificate', $certPath);
        $this->components->twoColumnDetail('Private key', $keyPath);
        $this->newLine();
        $this->line('Point the vault at it (host .env):');
        $this->line("  <fg=cyan>DBVAULT_CA_CERT={$certPath}</>");
        $this->line("  <fg=cyan>DBVAULT_CA_KEY={$keyPath}</>");
        $this->newLine();
        $this->line('And give the SAME ca.crt to nginx as ssl_client_certificate.');

        return self::SUCCESS;
    }
}
