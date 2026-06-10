<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    public function definition(): array
    {
        return [
            'store_id'           => Store::factory(),
            'name'               => $this->faker->unique()->words(3, true) . ' Document',
            'document_type'      => $this->faker->randomElement(['contract', 'id_verification', 'background_check', 'tax_form', 'offer_letter']),
            'requires_signature' => false,
            'description'        => null,
            'created_by'         => null,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(['store_id' => $store->id]);
    }

    public function requiresSignature(): static
    {
        return $this->state(['requires_signature' => true]);
    }
}
