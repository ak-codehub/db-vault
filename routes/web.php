<?php

declare(strict_types=1);

use DbVault\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DB Vault SPA boot
|--------------------------------------------------------------------------
|
| Mounted by DbVault\DbVaultServiceProvider at "{path}" (default "vault").
| A single catch-all serves the SPA boot view for every non-API URL under
| the mount point, so client-side (vue-router) routes such as /vault/requests
| or /vault/two-factor deep-link straight into the app. The JSON API group is
| registered first, so "{path}/api/*" never reaches this catch-all.
|
*/

Route::get('/{any?}', [SpaController::class, 'index'])
    ->where('any', '.*')
    ->name('spa');
