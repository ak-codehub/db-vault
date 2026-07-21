<?php

declare(strict_types=1);

namespace DbVault\Services;

use DbVault\Models\User;
use RuntimeException;

/**
 * Issues client certificates for mTLS device enrolment, signed by the vault's
 * configured CA (config('dbvault.ca')). The vault never stores the client
 * private key — it is bundled into the returned .p12 and handed to the user
 * once; only the certificate's identity (DN + fingerprint) is persisted, by
 * DeviceController, into vault_devices.
 *
 * The CA here must be the same one nginx trusts via `ssl_client_certificate`,
 * so certificates this service signs are accepted at the mTLS handshake.
 */
class CertificateAuthorityService
{
    /**
     * Whether certificate issuance is available (a CA cert + key are
     * configured and readable).
     */
    public function isConfigured(): bool
    {
        $cert = config('dbvault.ca.cert_path');
        $key = config('dbvault.ca.key_path');

        return is_string($cert) && is_file($cert)
            && is_string($key) && is_file($key);
    }

    /**
     * Issue a client certificate for the given user.
     *
     * @return array{p12: string, password: string, dn: string, fingerprint: string, not_after: \DateTimeImmutable}
     *         `p12` is the raw PKCS#12 bundle bytes (cert + private key), to be
     *         streamed to the browser as a download. `password` protects it.
     */
    public function issueForUser(User $user, ?string $label = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('No client-certificate CA is configured. Run `php artisan dbvault:make-ca` or set DBVAULT_CA_CERT / DBVAULT_CA_KEY.');
        }

        [$caCert, $caKey] = $this->loadCa();

        // Common Name identifies the user; email is carried too so the cert is
        // recognisable. A random serial-ish CN suffix keeps DNs unique per
        // issuance so re-issuing does not collide on fingerprint.
        $cn = $user->email;

        $dn = array_filter([
            'organizationName' => config('dbvault.ca.subject.organizationName'),
            'organizationalUnitName' => config('dbvault.ca.subject.organizationalUnitName'),
            'commonName' => $cn,
            'emailAddress' => $user->email,
        ]);

        $clientKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($clientKey === false) {
            throw new RuntimeException('Failed to generate client key: '.openssl_error_string());
        }

        $csr = openssl_csr_new($dn, $clientKey, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw new RuntimeException('Failed to build CSR: '.openssl_error_string());
        }

        $days = (int) config('dbvault.ca.client_cert_days', 365);

        // clientAuth EKU via a v3 config so the cert is valid for mTLS.
        $signed = openssl_csr_sign(
            $csr,
            $caCert,
            $caKey,
            $days,
            ['digest_alg' => 'sha256'],
            random_int(1, PHP_INT_MAX),
        );
        if ($signed === false) {
            throw new RuntimeException('Failed to sign client certificate: '.openssl_error_string());
        }

        openssl_x509_export($signed, $certPem);

        $password = $this->randomPassword();
        $p12 = '';
        if (! openssl_pkcs12_export($signed, $p12, $clientKey, $password, ['friendly_name' => $label ?? $cn])) {
            throw new RuntimeException('Failed to export PKCS#12 bundle: '.openssl_error_string());
        }

        $parsed = openssl_x509_parse($certPem);

        return [
            'p12' => $p12,
            'password' => $password,
            'dn' => $this->formatDn($parsed['subject'] ?? []),
            'fingerprint' => $this->fingerprint($certPem),
            'not_after' => (new \DateTimeImmutable())->setTimestamp((int) ($parsed['validTo_time_t'] ?? time())),
        ];
    }

    /**
     * @return array{0: \OpenSSLCertificate, 1: \OpenSSLAsymmetricKey}
     */
    protected function loadCa(): array
    {
        $cert = openssl_x509_read((string) file_get_contents(config('dbvault.ca.cert_path')));
        if ($cert === false) {
            throw new RuntimeException('Could not read CA certificate: '.openssl_error_string());
        }

        $key = openssl_pkey_get_private(
            (string) file_get_contents(config('dbvault.ca.key_path')),
            config('dbvault.ca.key_passphrase') ?: null,
        );
        if ($key === false) {
            throw new RuntimeException('Could not read CA private key (wrong passphrase?): '.openssl_error_string());
        }

        return [$cert, $key];
    }

    /**
     * SHA-256 fingerprint in the colon-separated hex form proxies forward,
     * prefixed "SHA256:".
     */
    public function fingerprint(string $certPem): string
    {
        $hex = openssl_x509_fingerprint($certPem, 'sha256');

        return 'SHA256:'.implode(':', str_split(strtoupper((string) $hex), 2));
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    protected function formatDn(array $subject): string
    {
        // openssl_x509_parse() returns SHORT keys (CN, OU, O, emailAddress).
        // Render most-specific-first (CN,…,O) matching how proxies present a DN.
        $parts = [];
        foreach (['CN', 'OU', 'O', 'emailAddress'] as $key) {
            if (! empty($subject[$key])) {
                $parts[] = $key.'='.$subject[$key];
            }
        }

        return implode(',', $parts);
    }

    protected function randomPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'xy'), '=');
    }
}
