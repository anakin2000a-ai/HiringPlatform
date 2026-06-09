<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Store;
use App\Models\WorkflowActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowActivity>
 */
class WorkflowActivityFactory extends Factory
{
    protected $model = WorkflowActivity::class;

    public function definition(): array
    {
        return [
            'application_id'   => Application::factory(),
            'store_id'         => Store::factory(),
            'workflow_stage_id' => null,
            'actor_type'       => null,
            'actor_id'         => null,
            'event_type'       => 'stage_moved',
            'old_value'        => null,
            'new_value'        => null,
            'metadata'         => null,
            'created_at'       => now(),
        ];
    }

    public function byUser(int $userId): static
    {
        return $this->state([
            'actor_type' => 'user',
            'actor_id'   => $userId,
        ]);
    }
}
