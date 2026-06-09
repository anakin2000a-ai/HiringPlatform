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

        // Stores — collection routes (no store model in URL)
        Route::get('stores', [StoreController::class, 'index']);
        Route::post('stores', [StoreController::class, 'store']);

        // Store instance routes — EnsureStoreAccess verifies user can access {store}
        Route::middleware('store.access')->group(function (): void {
            Route::get('stores/{store}', [StoreController::class, 'show']);
            Route::patch('stores/{store}', [StoreController::class, 'update']);
            Route::delete('stores/{store}', [StoreController::class, 'destroy']);

            // Nested store resources — added per phase
            // Phase 3: Route::apiResource('stores/{store}/workflows', WorkflowController::class);
            // Phase 4: Route::apiResource('stores/{store}/job-openings', JobOpeningController::class);
            // Phase 5: Route::apiResource('stores/{store}/applications', ApplicationController::class);
            // Phase 7: Route::apiResource('stores/{store}/questionnaires', QuestionnaireTemplateController::class);
            // Phase 8: Route::apiResource('stores/{store}/documents', DocumentTemplateController::class);
            // Phase 9: Route::apiResource('stores/{store}/automation-rules', AutomationRuleController::class);
        });
    });
});
