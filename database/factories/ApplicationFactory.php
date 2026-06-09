<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\JobOpening;
use App\Models\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'applicant_id' => Applicant::factory(),
            'job_opening_id' => JobOpening::factory(),
            'current_stage_id' => null,
            'status' => 'active',
            'score' => null,
            'applied_at' => now(),
            'rejected_at' => null,
            'hired_at' => null,
            'withdrawn_at' => null,
        ];
    }

    public function rejected(): static
    {
        return $this->state([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);
    }

    public function hired(): static
    {
        return $this->state([
            'status' => 'hired',
            'hired_at' => now(),
        ]);
    }

    public function atStage(WorkflowStage $stage): static
    {
        return $this->state(['current_stage_id' => $stage->id]);
    }
}
