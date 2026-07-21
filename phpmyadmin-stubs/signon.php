<?php

declare(strict_types=1);

/**
 * DB Vault → phpMyAdmin signon bridge.
 *
 * phpMyAdmin runs as its OWN PHP process (its own Composer vendor tree, which
 * cannot be loaded inside the Laravel app), served by the web server under
 * "{vault-path}/pma" on the host's own origin/TLS. Because Laravel is not
 * booted here, this bridge exchanges the vault's one-time launch token for the
 * temporary MySQL credential over a same-origin HTTP call to the vault's
 * token-exchange endpoint, guarded by a shared secret.
 *
 * phpMyAdmin (auth_type = 'signon') sends users here when they have no active
 * session. We receive the vault's one-time ?token=, exchange it server-to-
 * server for the credential, stash it in the signon session the way pMA
 * expects, then hand control back to pMA. The token is opaque and single-use;
 * the MySQL password never reaches the browser.
 *
 * Config comes from the host app's environment (read straight from the process
 * env, since Laravel's config() is unavailable here):
 *   DBVAULT_EXCHANGE_URL   full URL to {vault-path}/api/sessions/exchange
 *                          (defaults to same-origin "/{DBVAULT_PATH}/api/…")
 *   DBVAULT_SIGNON_SECRET  shared secret sent as the X-DbVault-Signon header
 */

session_name('SignonSession');
session_start();

$token = $_GET['token'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo 'Missing signon token. Launch phpMyAdmin from the DB Vault panel.';
    exit;
}

// Config arrives via FastCGI params ($_SERVER) — set in the nginx pma
// location — or the process environment. $_SERVER is checked first because
// fastcgi_param is the documented delivery mechanism here.
$readEnv = static function (string $key): string {
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    $v = getenv($key);

    return $v === false ? '' : (string) $v;
};

// Resolve the exchange URL. Prefer an explicit override; otherwise build a
// same-origin absolute URL from the current request and the vault path.
$exchangeUrl = $readEnv('DBVAULT_EXCHANGE_URL');
if ($exchangeUrl === '') {
    $vaultPath = trim($readEnv('DBVAULT_PATH') ?: 'vault', '/');
    $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $prefix = $vaultPath === '' ? 'api' : $vaultPath.'/api';
    $exchangeUrl = $scheme.'://'.$host.'/'.$prefix.'/sessions/exchange';
}

$secret = $readEnv('DBVAULT_SIGNON_SECRET');

$ch = curl_init($exchangeUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    // Same-origin call to our own host; skip peer verification for local
    // self-signed certs. The endpoint is loopback-reachable and secret-gated.
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-DbVault-Signon: '.$secret,
    ],
    CURLOPT_POSTFIELDS => json_encode(['token' => $token]),
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200) {
    http_response_code(403);
    echo 'This session is no longer valid. Return to DB Vault and launch again.';
    exit;
}

$cred = json_decode((string) $body, true);

// Hand the credential to phpMyAdmin via the signon session structure.
$_SESSION['PMA_single_signon_user'] = $cred['username'];
$_SESSION['PMA_single_signon_password'] = $cred['password'];
$_SESSION['PMA_single_signon_host'] = $cred['host'];
$_SESSION['PMA_single_signon_port'] = (string) $cred['port'];
// Restrict the visible database to the brokered target.
$_SESSION['PMA_single_signon_cfgupdate'] = ['only_db' => $cred['database']];

session_write_close();

header('Location: ./index.php');
exit;
