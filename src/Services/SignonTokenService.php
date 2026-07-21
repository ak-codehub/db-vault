<?php

declare(strict_types=1);

namespace DbVault\Services;

use DbVault\Enums\SessionStatus;
use DbVault\Models\SignonToken;
use Illuminate\Support\Facades\Hash;

/**
 * Redeems a one-time phpMyAdmin launch token for the underlying temporary
 * MySQL credential. Shared by both signon paths:
 *   - the legacy HTTP endpoint DbSessionController::exchange() (external
 *     phpMyAdmin process, shared-secret guarded), and
 *   - the native in-process signon (phpMyAdmin served via PmaProxyController;
 *     no HTTP hop, no shared secret — it already runs inside this app).
 *
 * The credential is returned to the caller, never to the developer's browser.
 */
class SignonTokenService
{
    /**
     * Redeem a raw launch token. Returns the credential array on success, or
     * null if the token is missing/invalid/expired or its session is no longer
     * active. Single-use: a matched token is burned before returning.
     *
     * @return array{username:string,password:?string,host:string,port:int,database:?string}|null
     */
    public function redeem(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        // Bcrypt hashes aren't directly queryable, so scan the small set of
        // still-valid tokens and hash-check. Tokens are few and short-lived.
        $candidate = SignonToken::query()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get()
            ->first(fn (SignonToken $t) => Hash::check($raw, $t->token_hash));

        if ($candidate === null) {
            return null;
        }

        $candidate->forceFill(['used_at' => now()])->save(); // single-use

        $session = $candidate->dbSession;
        if ($session === null || $session->status !== SessionStatus::Active || $session->isExpired()) {
            return null;
        }

        return [
            'username' => $session->mysql_username,
            'password' => $session->secret,
            'host' => $this->host(),
            'port' => $this->port(),
            'database' => config('dbvault.target_database'),
        ];
    }

    /**
     * Provisioner host, stripped of any ":port" suffix (the .env convention
     * allows "127.0.0.1:3306" in DBVAULT_PROVISION_HOST).
     */
    private function host(): string
    {
        $host = (string) config('dbvault.provisioner.host', config('dbvault.rds_host', '127.0.0.1'));

        return str_contains($host, ':') ? explode(':', $host, 2)[0] : $host;
    }

    private function port(): int
    {
        $host = (string) config('dbvault.provisioner.host', '');
        if (str_contains($host, ':')) {
            return (int) explode(':', $host, 2)[1];
        }

        return (int) config('dbvault.provisioner.port', config('dbvault.rds_port', 3306));
    }
}
