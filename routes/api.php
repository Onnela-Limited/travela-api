<?php

use App\Http\Controllers\Admin\AdminDashboard;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\RevenueDashboard;
use App\Http\Controllers\Admin\ServiceProviderSimsController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\Api\Admin\EsimController as AdminEsimController;
use App\Http\Controllers\Api\Admin\EsimImportBatchController;
use App\Http\Controllers\Api\Admin\EsimImportItemController;
use App\Http\Controllers\Api\AdminUserEsimController;
use App\Http\Controllers\Api\AgentOrderLookupController;
use App\Http\Controllers\Api\EsimController;
use App\Http\Controllers\Api\EsimLookupController;
use App\Http\Controllers\Api\PhysicalSimIssuanceController;
use App\Http\Controllers\Api\UserEsimController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\BundleTypeController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EvPayController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
    Route::post('/verify-email', [VerifyEmailController::class, 'verify']);
    Route::post('/email/resend', [VerifyEmailController::class, 'resend'])
        ->middleware('auth:sanctum');
});

// EvPay REST card webhook (no user auth)
Route::post('/payments/evpay/callback', [EvPayController::class, 'callback']);
Route::post('/evpay/webhook', [EvPayController::class, 'callback']);

Route::prefix('public')->group(function () {
    // Vodacom callbacks
    Route::post('/sims-balances/callback', [EsimController::class, 'simsBalancesCallback']);
    Route::post('/my-recharge-callback-url', [EsimController::class, 'rechargeCallback']);

    // kyc
    Route::get('/kyc', [KycController::class, 'show']);     // get my KYC
    Route::post('/kyc', [KycController::class, 'store']);   // create/update
    Route::delete('/kyc', [KycController::class, 'destroy']);

    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/{iso2}/providers', [CountryController::class, 'providers']);

    Route::get('/bundle-types', [BundleTypeController::class, 'index']);
    Route::get('/bundles', [BundleController::class, 'index']); // ?active=1
    Route::get('/providers/{provider}/bundles', [ProviderController::class, 'bundles']); // ?country=TZ&type=DATA&active=1

});

// Admin/system Vodacom proxy routes (do not use for normal customer dashboard)
Route::prefix('esim')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/organisation/balance', [EsimController::class, 'organisationBalance']);
    Route::get('/networks', [EsimController::class, 'networks']);
    Route::get('/products', [EsimController::class, 'products']);
    Route::get('/sims', [EsimController::class, 'sims']);
    Route::post('/sims', [EsimController::class, 'storeSim']);
    Route::post('/sims/activate', [EsimController::class, 'activate']);
    Route::post('/sims/suspend', [EsimController::class, 'suspend']);
    Route::get('/usage', [EsimController::class, 'usage']);
    Route::get('/usage-details', [EsimController::class, 'usageDetails']);
    Route::get('/recharges', [EsimController::class, 'recharges']);
    Route::post('/recharge', [EsimController::class, 'recharge']);
    Route::get('/sims-balances', [EsimController::class, 'simsBalances']);
});

Route::prefix('me')->middleware('auth:sanctum')->group(function () {
    Route::get('/orders', [OrderController::class, 'myOrders']);
    Route::get('/orders/search', [OrderController::class, 'searchByOrderNumber']);
    Route::get('/esims', [UserEsimController::class, 'index']);
    Route::get('/esims/balance-status', [UserEsimController::class, 'balanceStatus']);
    Route::get('/esims/{userEsim}/activation', [UserEsimController::class, 'activation'])->whereNumber('userEsim');
    Route::post('/esims/{userEsim}/device-activated', [UserEsimController::class, 'markDeviceActivated'])->whereNumber('userEsim');
    Route::get('/esims/assignment-status', [UserEsimController::class, 'assignmentStatus']);
    Route::post('/esims/register', [UserEsimController::class, 'register']);
    Route::patch('/esims/activation-date', [UserEsimController::class, 'updateActivationDate']);
    Route::get('/recharges', [UserEsimController::class, 'recharges']);
    Route::get('/usage', [UserEsimController::class, 'usage']);
    Route::get('/usage-details', [UserEsimController::class, 'usageDetails']);
    Route::post('/recharge', [UserEsimController::class, 'recharge']);

    Route::patch('/agent-location', [AgentController::class, 'updateMyLocation'])
        ->middleware('agent');
});

// Agent app routes (use agent token — not /api/admin/*)
Route::prefix('agent')->middleware(['auth:sanctum', 'agent'])->group(function () {
    Route::get('/orders/search', [AgentOrderLookupController::class, 'searchByOrderSuffix']);
    Route::get('/orders/unassigned-physical', [AgentOrderLookupController::class, 'unassignedPhysicalOrders']);
    Route::get('/orders/by-msisdn', [AgentOrderLookupController::class, 'byMsisdn']);
    Route::get('/esims/search', [EsimLookupController::class, 'searchByIccidSuffix']);
    Route::post('/orders/assign-sim', [PhysicalSimIssuanceController::class, 'assignPhysicalByOrder']);
    Route::post('/orders/issue-physical', [PhysicalSimIssuanceController::class, 'issueByOrder']);
    Route::get('/orders/issued-physical', [PhysicalSimIssuanceController::class, 'issuedPhysical']);
    Route::post('/orders/confirm-activation', [PhysicalSimIssuanceController::class, 'confirmActivation']);
    Route::post('/orders/mark-paid', [AgentOrderLookupController::class, 'markPaid']);
    Route::post('/physical-sims/assign', [PhysicalSimIssuanceController::class, 'assignWalkIn']);
    Route::patch('/location', [AgentController::class, 'updateMyLocation']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/user-esims', [AdminUserEsimController::class, 'index']);
    Route::get('/user-esims/counts', [AdminUserEsimController::class, 'userEsimCounts']);
    Route::post('/user-esims', [AdminUserEsimController::class, 'store']);
    Route::delete('/user-esims/{id}', [AdminUserEsimController::class, 'destroy']);
    Route::post('/user-esims/sync-from-vodacom', [AdminUserEsimController::class, 'syncFromVodacom']);

    // Customers
    Route::get('/customers', [CustomerController::class, 'index']);

    // Agents
    Route::get('/agents', [AgentController::class, 'index']);
    Route::post('/agents', [AgentController::class, 'store']);

    // Orders (admin)
    Route::get('/orders', [OrderController::class, 'getOrders']);
    Route::get('/orders/search', [OrderController::class, 'searchByOrderNumber']);
    Route::get('/orders/unassigned-physical', [AgentOrderLookupController::class, 'unassignedPhysicalOrders']);
    Route::post('/orders/issue-physical', [PhysicalSimIssuanceController::class, 'issueByOrder']);
    Route::post('/orders/assign-sim', [PhysicalSimIssuanceController::class, 'assignPhysicalByOrder']);
    Route::post('/physical-sims/assign', [PhysicalSimIssuanceController::class, 'assignWalkIn']);
    Route::post('/user-esims/{id}/issue-physical', [PhysicalSimIssuanceController::class, 'issueByAssignment']);
    Route::get('/users/{userId}/orders', [OrderController::class, 'getOrdersByUser']);

    // Dashboard (admin)
    Route::get('/revenue/stats', [RevenueDashboard::class, 'stats']);
    Route::get('/dashboard/stats', [AdminDashboard::class, 'stats']);
    Route::get('/dashboard/esims-issued', [AdminDashboard::class, 'esimsIssued']);
    Route::get('/dashboard/esim-activities', [AdminDashboard::class, 'esimIssuedActivities']);

    // eSIM batch import (one page/item at a time — admin frontend)
    Route::post('/esim-import-batches', [EsimImportBatchController::class, 'store']);
    Route::get('/esim-import-batches/{batch}', [EsimImportBatchController::class, 'show']);
    Route::post('/esim-import-batches/{batch}/items', [EsimImportBatchController::class, 'storeItem']);
    Route::post('/esim-import-batches/{batch}/items/preview', [EsimImportBatchController::class, 'previewItem']);
    Route::post('/esim-import-batches/{batch}/spreadsheet', [EsimImportBatchController::class, 'uploadSpreadsheet']);
    Route::post('/esim-import-batches/{batch}/confirm-all', [EsimImportBatchController::class, 'confirmAll']);
    Route::post('/esim-import-batches/{batch}/finish', [EsimImportBatchController::class, 'finish']);
    Route::post('/esim-import-items/{item}/confirm', [EsimImportItemController::class, 'confirm']);
    Route::post('/esim-import-items/{item}/skip', [EsimImportItemController::class, 'skip']);
    Route::post('/esim-import-items/{item}/retry-activation', [EsimImportItemController::class, 'retryActivation']);
    Route::post('/esim-import-items/{item}/retry', [EsimImportItemController::class, 'retry']);

    // Imported eSIM inventory (admin)
    Route::get('/esims', [AdminEsimController::class, 'index']);
    Route::get('/esims/{esim}/qr', [AdminEsimController::class, 'qr'])->whereNumber('esim');

    // DEPRECATED: bulk PDF import — use esim-import-batches instead
    // Route::post('/esims/import', [EsimImportController::class, 'import']);

    // Service provider SIM inventory (local DB)
    Route::get('/service-providers/{provider}/sims', [ServiceProviderSimsController::class, 'index']);
    Route::get('/esims/search', [EsimLookupController::class, 'searchByIccidSuffix']);

    // SIM stock levels + low-inventory alerts
    Route::get('/inventory/stock', [InventoryController::class, 'stock']);
});

Route::middleware('auth:sanctum')->get('/orders/search', [OrderController::class, 'searchByOrderNumber']);

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Orders (merged create / checkout)
    Route::post('/orders', [OrderController::class, 'storeOrder']);

    // Order routes
    // routes/api.php
    Route::get('/orders/{draft_id}', [OrderController::class, 'show']);
    Route::put('/orders/{draft_id}', [OrderController::class, 'update']);
    Route::delete('/orders/{draft_id}', [OrderController::class, 'destroy']);

    Route::post('/orders/{orderId}/prepare-evpay', [EvPayController::class, 'preparePayment']);
    Route::post('/orders/{orderId}/evpay-checkout-url', [EvPayController::class, 'createCheckoutUrl']);
});

// Authenticated user info (no email verification required)
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

require __DIR__.'/partners.php';
