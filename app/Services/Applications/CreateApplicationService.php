<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\JobOpening;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateApplicationService
{
    public function __construct(private readonly ApplicantService $applicantService) {}

    public function create(JobOpening $jobOpening, array $applicantData): Application
    {
        $workflow = $jobOpening->hiringWorkflow;

        if ($workflow === null) {
            throw ValidationException::withMessages([
                'job_opening_id' => ['This job opening has no associated workflow.'],
            ]);
        }

        $initialStage = $workflow->initialStage;

        if ($initialStage === null) {
            throw ValidationException::withMessages([
                'job_opening_id' => ['The workflow for this job opening has no initial stage.'],
            ]);
        }

        return DB::transaction(function () use ($jobOpening, $applicantData, $initialStage): Application {
            $applicant = $this->applicantService->findOrCreate($applicantData);

            $alreadyApplied = Application::where('applicant_id', $applicant->id)
                ->where('job_opening_id', $jobOpening->id)
                ->exists();

            if ($alreadyApplied) {
                throw ValidationException::withMessages([
                    'job_opening_id' => ['You have already applied to this job opening.'],
                ]);
            }

            $application = Application::create([
                'applicant_id' => $applicant->id,
                'job_opening_id' => $jobOpening->id,
                'current_stage_id' => $initialStage->id,
                'status' => 'active',
                'applied_at' => now(),
            ]);

            return $application->load(['applicant', 'jobOpening', 'currentStage']);
        });
    }
}
