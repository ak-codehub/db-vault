<?php

declare(strict_types=1);

namespace DbVault\Services;

use Aws\SecretsManager\SecretsManagerClient;
use RuntimeException;

/**
 * Thin wrapper around AWS Secrets Manager used to fetch the MASTER MySQL
 * credential that DbVault\Services\ProvisionerService authenticates to the
 * target RDS instance with. No static AWS keys are configured - this relies
 * on an instance/task role granting secretsmanager:GetSecretValue.
 *
 * Stub: the real implementation should add caching (the master secret
 * rotates independently) and surface rotation failures distinctly from
 * "secret not found".
 */
class SecretsManagerService
{
    protected SecretsManagerClient $client;

    public function __construct(?SecretsManagerClient $client = null)
    {
        $this->client = $client ?? new SecretsManagerClient([
            'version' => 'latest',
            'region' => config('dbvault.aws_region'),
        ]);
    }

    /**
     * Fetch and decode the master MySQL credential (username/password/host)
     * from AWS Secrets Manager, at the path configured via
     * config('dbvault.master_secret') / DBVAULT_MASTER_SECRET.
     *
     * @return array{username: string, password: string, host?: string, port?: int}
     */
    public function getMasterCredential(): array
    {
        // TODO: call $this->client->getSecretValue([...]), json_decode the
        // SecretString, and validate its shape before returning it to the
        // Provisioner. Consider short-lived in-process caching to avoid a
        // Secrets Manager round trip per provision.
        throw new RuntimeException(
            'SecretsManagerService::getMasterCredential() is a stub and is not yet implemented.'
        );
    }
}
