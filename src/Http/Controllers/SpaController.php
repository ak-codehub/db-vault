<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the SPA boot document for every non-API URL under the mount point.
 * The view emits `window.DbVault = { basePath, apiBase, csrf }` before the
 * compiled bundle loads, which resources/js/api.js reads to configure the
 * axios client and vue-router history base. No authentication is enforced
 * here: the bundle boots for guests too and the client redirects to /login
 * when GET {path}/api/me returns 401.
 */
class SpaController extends Controller
{
    public function index(): View
    {
        $path = trim((string) config('dbvault.path', 'vault'), '/');

        // Leading-slash mount path for vue-router's createWebHistory(), e.g.
        // "/vault", or "/" when the panel is mounted on a bare subdomain.
        $basePath = '/'.$path;

        // Absolute API base the axios client posts to.
        $apiBase = url($path === '' ? 'api' : $path.'/api');

        return view('db-vault::app', [
            'basePath' => $basePath,
            'apiBase' => $apiBase,
            // Published by `php artisan vendor:publish --tag=db-vault-assets`
            // (or a symlink) into public/vendor/db-vault.
            'jsUrl' => asset('vendor/db-vault/app.js'),
            'cssUrl' => asset('vendor/db-vault/app.css'),
        ]);
    }
}
