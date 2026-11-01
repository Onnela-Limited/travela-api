<?php

use App\Http\Controllers\Api\PartnerApplicationController;
use Illuminate\Support\Facades\Route;

Route::post('/public/partner-applications', [PartnerApplicationController::class, 'store'])
    ->middleware('throttle:10,1');

// Both credentials are sent in the body, never in a URL.
Route::post('/public/partner-applications/status', [PartnerApplicationController::class, 'show'])
    ->middleware('throttle:30,1');

Route::prefix('partner-auth')->group(function () {
    Route::post('/code', [\App\Http\Controllers\Api\PartnerAuthController::class, 'sendCode'])->middleware('throttle:10,1');
    Route::post('/verify', [\App\Http\Controllers\Api\PartnerAuthController::class, 'verify'])->middleware('throttle:30,1');
    Route::middleware(\App\Http\Middleware\AuthenticatePartnerSession::class)->group(function () {
        Route::get('/me', [\App\Http\Controllers\Api\PartnerAuthController::class, 'me']);
        Route::post('/logout', [\App\Http\Controllers\Api\PartnerAuthController::class, 'logout']);
    });
});

Route::prefix('admin/partner-applications')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\PartnerApplicationReviewController::class, 'index']);
    Route::get('/{application}', [\App\Http\Controllers\Api\Admin\PartnerApplicationReviewController::class, 'show'])->whereNumber('application');
    Route::get('/{application}/documents/{kind}', [\App\Http\Controllers\Api\Admin\PartnerApplicationReviewController::class, 'document'])->whereNumber('application');
    Route::post('/{application}/review', [\App\Http\Controllers\Api\Admin\PartnerApplicationReviewController::class, 'review'])->whereNumber('application');
});

Route::get('/public/partner-referrals/{code}', [\App\Http\Controllers\Api\PartnerReferralController::class, 'resolve'])->middleware('throttle:120,1');
Route::middleware(\App\Http\Middleware\AuthenticatePartnerSession::class)->prefix('partner-auth')->group(function () {
    Route::get('/referral', [\App\Http\Controllers\Api\PartnerReferralController::class, 'show'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::get('/referral/qr', [\App\Http\Controllers\Api\PartnerReferralController::class, 'qr'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
});

Route::middleware(\App\Http\Middleware\AuthenticatePartnerSession::class)->prefix('partner-auth')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Api\PartnerSalesController::class, 'show'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::get('/sales', [\App\Http\Controllers\Api\PartnerSalesController::class, 'index'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
});

Route::middleware(\App\Http\Middleware\AuthenticatePartnerSession::class)->prefix('partner-auth')->group(function () {
    Route::get('/payouts', [\App\Http\Controllers\Api\PartnerPayoutController::class, 'index'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner');
    Route::post('/payouts', [\App\Http\Controllers\Api\PartnerPayoutController::class, 'store'])->middleware('throttle:10,1')->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner');
});
Route::prefix('admin/partner-payouts')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PartnerPayoutController::class, 'adminIndex']);
    Route::get('/{payout}', [\App\Http\Controllers\Api\PartnerPayoutController::class, 'show'])->whereNumber('payout');
    Route::post('/{payout}/review', [\App\Http\Controllers\Api\PartnerPayoutController::class, 'review'])->whereNumber('payout');
});

Route::middleware(\App\Http\Middleware\AuthenticatePartnerSession::class)->prefix('partner-auth')->group(function () {
    Route::get('/bulk/catalog', [\App\Http\Controllers\Api\PartnerBulkController::class, 'catalog'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::get('/bulk', [\App\Http\Controllers\Api\PartnerBulkController::class, 'index'])->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::post('/bulk', [\App\Http\Controllers\Api\PartnerBulkController::class, 'store'])->middleware('throttle:10,1')->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::post('/bulk/{bulk}/checkout', [\App\Http\Controllers\Api\PartnerBulkController::class, 'checkout'])->whereNumber('bulk')->middleware('throttle:10,1')->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::post('/bulk/{bulk}/refresh-payment', [\App\Http\Controllers\Api\PartnerBulkController::class, 'refreshPayment'])->whereNumber('bulk')->middleware('throttle:30,1')->middleware(\App\Http\Middleware\AuthorizePartnerRole::class.':owner,manager');
    Route::get('/stock', [\App\Http\Controllers\Api\PartnerBulkController::class, 'stock']);
    Route::post('/stock/{unit}/assign', [\App\Http\Controllers\Api\PartnerBulkController::class, 'assign'])->whereNumber('unit')->middleware('throttle:10,1');
    Route::post('/stock/{unit}/handover', [\App\Http\Controllers\Api\PartnerBulkController::class, 'handover'])->whereNumber('unit');
});
Route::prefix('admin/partner-bulk')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PartnerBulkController::class, 'adminIndex']);
    Route::get('/{bulk}', [\App\Http\Controllers\Api\PartnerBulkController::class, 'show'])->whereNumber('bulk');
    Route::post('/{bulk}/review', [\App\Http\Controllers\Api\PartnerBulkController::class, 'review'])->whereNumber('bulk');
});

Route::post('/public/partner-team/invitation',[\App\Http\Controllers\Api\PartnerTeamController::class,'invitation'])->middleware('throttle:30,1');
Route::middleware([\App\Http\Middleware\AuthenticatePartnerSession::class,\App\Http\Middleware\AuthorizePartnerRole::class.':owner'])->prefix('partner-auth/team')->group(function () {
    Route::get('/',[\App\Http\Controllers\Api\PartnerTeamController::class,'index']);
    Route::post('/',[\App\Http\Controllers\Api\PartnerTeamController::class,'store'])->middleware('throttle:10,1');
    Route::post('/{member}',[\App\Http\Controllers\Api\PartnerTeamController::class,'change'])->whereNumber('member')->middleware('throttle:20,1');
});

Route::middleware([\App\Http\Middleware\AuthenticatePartnerSession::class,\App\Http\Middleware\AuthorizePartnerRole::class.':owner'])->prefix('partner-auth')->group(function () {
 Route::get('/settings',[\App\Http\Controllers\Api\PartnerSettingsController::class,'show']);
 Route::post('/settings',[\App\Http\Controllers\Api\PartnerSettingsController::class,'update'])->middleware('throttle:10,1');
});

Route::middleware(\App\Http\Middleware\AuthenticatePartnerSession::class)->prefix('partner-auth/notifications')->group(function(){
 Route::get('/',[\App\Http\Controllers\Api\PartnerNotificationController::class,'index']);
 Route::post('/{notification}/read',[\App\Http\Controllers\Api\PartnerNotificationController::class,'read'])->whereNumber('notification')->middleware('throttle:60,1');
});
