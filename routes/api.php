<?php

declare(strict_types=1);

use DbVault\Http\Controllers\Api\AccessRequestController;
use DbVault\Http\Controllers\Api\ApprovalController;
use DbVault\Http\Controllers\Api\AuditController;
use DbVault\Http\Controllers\Api\AuthController;
use DbVault\Http\Controllers\Api\DashboardController;
use DbVault\Http\Controllers\Api\DbSessionController;
use DbVault\Http\Controllers\Api\DeviceController;
use DbVault\Http\Controllers\Api\SchemaController;
use DbVault\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DB Vault JSON API
|--------------------------------------------------------------------------
|
| Mounted by DbVault\DbVaultServiceProvider at "{path}/api" (default
| "vault/api") under the config('dbvault.middleware') group (the Laravel
| 'web' stack by default, which provides the session + CSRF cookie auth the
| SPA relies on). Every contract entry here matches a helper in
| resources/js/api.js one-to-one.
|
| The two auth endpoints below sit outside the vault.auth guard because they
| are how a session is established in the first place; everything else
| requires an authenticated vault user (vault.auth) that clears the
| viewDbVault gate (vault.gate).
|
*/

Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('two-factor-challenge');
// Confirm a forced enrollment (2FA required, user not yet enrolled). Sits
// outside vault.auth for the same reason as login: no session exists yet.
Route::post('two-factor-setup', [AuthController::class, 'confirmTwoFactorSetup'])->name('two-factor-setup');

Route::middleware(['vault.auth', 'vault.gate'])->group(function (): void {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('me', [AuthController::class, 'me'])->name('me');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Developer-facing request lifecycle.
    Route::get('requests', [AccessRequestController::class, 'index'])->name('requests.index');
    // Static segments must precede requests/{accessRequest} so they are not
    // captured by the wildcard route below.
    Route::get('requests/form-options', [SchemaController::class, 'formOptions'])->name('requests.form-options');
    Route::get('requests/tables', [SchemaController::class, 'tables'])->name('requests.tables');
    Route::post('requests', [AccessRequestController::class, 'store'])->name('requests.store');
    Route::get('requests/{accessRequest}', [AccessRequestController::class, 'show'])->name('requests.show');
    Route::post('requests/{accessRequest}/cancel', [AccessRequestController::class, 'cancel'])->name('requests.cancel');

    // Approver/admin-facing approval queue. {accessRequest} is the request
    // being decided on (the queue items carry the request id).
    Route::middleware('vault.role:approver,admin')->group(function (): void {
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('approvals/{accessRequest}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{accessRequest}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    });

    // Provisioned sessions: launch phpMyAdmin via a one-time token, revoke early.
    Route::get('sessions', [DbSessionController::class, 'index'])->name('sessions.index');
    Route::post('sessions/{dbSession}/launch', [DbSessionController::class, 'launch'])->name('sessions.launch');
    Route::post('sessions/{dbSession}/revoke', [DbSessionController::class, 'revoke'])->name('sessions.revoke');

    // Query audit trail (fed by the CloudWatch ingest job).
    Route::middleware('vault.role:approver,admin,auditor')
        ->get('audit', [AuditController::class, 'index'])
        ->name('audit.index');

    // Admin-only user onboarding & management.
    Route::middleware('vault.role:admin')->group(function (): void {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');

        // Enrolled mTLS client-certificate devices.
        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::post('devices/issue', [DeviceController::class, 'issue'])->name('devices.issue');
        Route::patch('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
    });
});
