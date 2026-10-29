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
