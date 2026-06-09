<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    Route::get('/health', [HealthController::class, 'index'])->name('health');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('logout');
    });

    // Authenticated business routes
    Route::middleware('auth:sanctum')->group(function (): void {

        // Users
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'show']);

        // Stores (resolved by slug via Store::getRouteKeyName())
        Route::apiResource('stores', StoreController::class);

        // Nested store resources — added per phase
        Route::prefix('stores/{store:slug}')->group(function (): void {
            // Phase 3: workflows
            // Phase 4: job-openings
            // Phase 5: applications
            // Phase 7: questionnaires
            // Phase 8: documents
            // Phase 9: automation-rules
        });
    });
});
