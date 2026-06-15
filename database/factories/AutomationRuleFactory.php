<?php

namespace Database\Factories;

use App\Enums\AutomationTrigger;
use App\Models\AutomationRule;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    protected $model = AutomationRule::class;

    public function definition(): array
    {
        return [
            'store_id'           => Store::factory(),
            'hiring_workflow_id' => null,
            'workflow_stage_id'  => null,
            'name'               => $this->faker->unique()->words(3, true) . ' Rule',
            'trigger'            => AutomationTrigger::ApplicationCreated,
            'conditions'         => null,
            'actions'            => [['type' => 'create_activity', 'event_type' => 'automation_fired']],
            'priority'           => 100,
            'is_active'          => true,
            'created_by'         => null,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(['store_id' => $store->id]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withTrigger(string $trigger): static
    {
        return $this->state(['trigger' => $trigger]);
    }
}
