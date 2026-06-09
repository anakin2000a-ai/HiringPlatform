<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\JobOpeningApplicationController;
use App\Http\Controllers\Api\V1\JobOpeningController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Http\Controllers\Api\V1\WorkflowStageController;
use App\Http\Controllers\Api\V1\WorkflowStageTransitionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    Route::get('/health', [HealthController::class, 'index'])->name('health');

    // Phase 5: Public apply route — no auth required
    Route::post('stores/{store}/job-openings/{jobOpening}/apply', [JobOpeningApplicationController::class, 'apply']);

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

            // Phase 3: Workflow configuration
            Route::get('stores/{store}/workflows', [WorkflowController::class, 'index']);
            Route::post('stores/{store}/workflows', [WorkflowController::class, 'store']);
            Route::get('stores/{store}/workflows/{workflow}', [WorkflowController::class, 'show']);
            Route::patch('stores/{store}/workflows/{workflow}', [WorkflowController::class, 'update']);
            Route::delete('stores/{store}/workflows/{workflow}', [WorkflowController::class, 'destroy']);

            Route::get('stores/{store}/workflows/{workflow}/stages', [WorkflowStageController::class, 'index']);
            Route::post('stores/{store}/workflows/{workflow}/stages', [WorkflowStageController::class, 'store']);
            Route::patch('stores/{store}/workflows/{workflow}/stages/{stage}', [WorkflowStageController::class, 'update']);
            Route::delete('stores/{store}/workflows/{workflow}/stages/{stage}', [WorkflowStageController::class, 'destroy']);

            Route::get('stores/{store}/workflows/{workflow}/transitions', [WorkflowStageTransitionController::class, 'index']);
            Route::post('stores/{store}/workflows/{workflow}/transitions', [WorkflowStageTransitionController::class, 'store']);
            Route::patch('stores/{store}/workflows/{workflow}/transitions/{transition}', [WorkflowStageTransitionController::class, 'update']);
            Route::delete('stores/{store}/workflows/{workflow}/transitions/{transition}', [WorkflowStageTransitionController::class, 'destroy']);

            // Phase 4: Job Openings
            Route::get('stores/{store}/job-openings', [JobOpeningController::class, 'index']);
            Route::post('stores/{store}/job-openings', [JobOpeningController::class, 'store']);
            Route::get('stores/{store}/job-openings/{jobOpening}', [JobOpeningController::class, 'show']);
            Route::patch('stores/{store}/job-openings/{jobOpening}', [JobOpeningController::class, 'update']);
            Route::delete('stores/{store}/job-openings/{jobOpening}', [JobOpeningController::class, 'destroy']);
            Route::post('stores/{store}/job-openings/{jobOpening}/publish', [JobOpeningController::class, 'publish']);
            Route::post('stores/{store}/job-openings/{jobOpening}/close', [JobOpeningController::class, 'close']);
            // Phase 5: Application management (authenticated + store-scoped)
            Route::get('stores/{store}/applications', [ApplicationController::class, 'index']);
            Route::get('stores/{store}/applications/{application}', [ApplicationController::class, 'show']);
            Route::patch('stores/{store}/applications/{application}', [ApplicationController::class, 'update']);
            // Phase 6: Manual stage movement and activity history
            Route::post('stores/{store}/applications/{application}/move-stage', [ApplicationController::class, 'moveStage']);
            Route::get('stores/{store}/applications/{application}/activities', [ApplicationController::class, 'activities']);
            // Phase 7: Route::apiResource('stores/{store}/questionnaires', QuestionnaireTemplateController::class);
            // Phase 8: Route::apiResource('stores/{store}/documents', DocumentTemplateController::class);
            // Phase 9: Route::apiResource('stores/{store}/automation-rules', AutomationRuleController::class);
        });
    });
});
