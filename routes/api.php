<?php

use App\Http\Controllers\Api\V1\ApplicantAnswerController;
use App\Http\Controllers\Api\V1\ConfigurationCopyController;
use App\Http\Controllers\Api\V1\ApplicantDocumentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\DocumentTemplateController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StageDocumentRequirementController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\JobOpeningApplicationController;
use App\Http\Controllers\Api\V1\JobOpeningController;
use App\Http\Controllers\Api\V1\QuestionnaireQuestionController;
use App\Http\Controllers\Api\V1\QuestionnaireTemplateController;
use App\Http\Controllers\Api\V1\StageQuestionnaireAssignmentController;
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

        // Phase 8: Stage document requirements (store resolved through stage->workflow->store)
        // Route::get('workflow-stages/{stage}/document-requirements', [StageDocumentRequirementController::class, 'index']);
        Route::post('workflow-stages/{stage}/document-requirements', [StageDocumentRequirementController::class, 'store']);
        // Route::get('stage-document-requirements/{requirement}', [StageDocumentRequirementController::class, 'show']);
        Route::patch('stage-document-requirements/{requirement}', [StageDocumentRequirementController::class, 'update']);
        Route::delete('stage-document-requirements/{requirement}', [StageDocumentRequirementController::class, 'destroy']);

        // Phase 8: Applicant documents (store resolved through application->jobOpening->store)
        Route::get('applications/{application}/documents', [ApplicantDocumentController::class, 'index']);
        Route::post('applications/{application}/documents', [ApplicantDocumentController::class, 'store']);
        Route::post('applicant-documents/{document}/submit', [ApplicantDocumentController::class, 'submit']);
        Route::post('applicant-documents/{document}/sign', [ApplicantDocumentController::class, 'sign']);
        Route::post('applicant-documents/{document}/approve', [ApplicantDocumentController::class, 'approve']);
        Route::post('applicant-documents/{document}/reject', [ApplicantDocumentController::class, 'reject']);

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

            // Route::get('stores/{store}/workflows/{workflow}/stages', [WorkflowStageController::class, 'index']);
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

            // Phase 7: Questionnaire templates and questions
            Route::get('stores/{store}/questionnaires', [QuestionnaireTemplateController::class, 'index']);
            Route::post('stores/{store}/questionnaires', [QuestionnaireTemplateController::class, 'store']);
            Route::get('stores/{store}/questionnaires/{questionnaire}', [QuestionnaireTemplateController::class, 'show']);
            Route::patch('stores/{store}/questionnaires/{questionnaire}', [QuestionnaireTemplateController::class, 'update']);
            Route::delete('stores/{store}/questionnaires/{questionnaire}', [QuestionnaireTemplateController::class, 'destroy']);

            // Route::get('stores/{store}/questionnaires/{questionnaire}/questions', [QuestionnaireQuestionController::class, 'index']);
            Route::post('stores/{store}/questionnaires/{questionnaire}/questions', [QuestionnaireQuestionController::class, 'store']);
            Route::patch('stores/{store}/questionnaires/{questionnaire}/questions/{question}', [QuestionnaireQuestionController::class, 'update']);
            Route::delete('stores/{store}/questionnaires/{questionnaire}/questions/{question}', [QuestionnaireQuestionController::class, 'destroy']);

            // Phase 7: Stage questionnaire assignment
            Route::post('stores/{store}/workflows/{workflow}/stages/{stage}/questionnaires', [StageQuestionnaireAssignmentController::class, 'store']);

            // Phase 7: Applicant answers
            Route::post('stores/{store}/applications/{application}/answers', [ApplicantAnswerController::class, 'store']);

            // Phase 8: Document templates (store-scoped)
            Route::get('stores/{store}/document-templates', [DocumentTemplateController::class, 'index']);
            Route::post('stores/{store}/document-templates', [DocumentTemplateController::class, 'store']);
            Route::get('stores/{store}/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'show']);
            Route::patch('stores/{store}/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'update']);
            Route::delete('stores/{store}/document-templates/{documentTemplate}', [DocumentTemplateController::class, 'destroy']);

            // Phase 10: Configuration copy
            Route::post('stores/{store}/copy-configuration', [ConfigurationCopyController::class, 'copy']);

            // Phase 9: Automation rules (store-scoped index/store; show/update/destroy by rule id only)
            Route::get('stores/{store}/automation-rules', [AutomationRuleController::class, 'index']);
            Route::post('stores/{store}/automation-rules', [AutomationRuleController::class, 'store']);
            Route::get('automation-rules/{automationRule}', [AutomationRuleController::class, 'show']);
            Route::patch('automation-rules/{automationRule}', [AutomationRuleController::class, 'update']);
            Route::delete('automation-rules/{automationRule}', [AutomationRuleController::class, 'destroy']);
        });
    });
});
