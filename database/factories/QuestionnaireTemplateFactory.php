<?php

namespace Database\Factories;

use App\Enums\QuestionnaireStatus;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionnaireTemplate>
 */
class QuestionnaireTemplateFactory extends Factory
{
    protected $model = QuestionnaireTemplate::class;

    public function definition(): array
    {
        return [
            'store_id'   => Store::factory(),
            'name'       => $this->faker->unique()->words(3, true) . ' Questionnaire',
            'version'    => 1,
            'status'     => QuestionnaireStatus::Active,
            'created_by' => null,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(['store_id' => $store->id]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => QuestionnaireStatus::Inactive]);
    }
}
