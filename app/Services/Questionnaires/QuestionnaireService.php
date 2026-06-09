<?php

namespace App\Services\Questionnaires;

use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class QuestionnaireService
{
    public function create(Store $store, array $data, User $creator): QuestionnaireTemplate
    {
        $version = $data['version'] ?? 1;

        $exists = QuestionnaireTemplate::where('store_id', $store->id)
            ->where('name', $data['name'])
            ->where('version', $version)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A questionnaire with this name and version already exists for this store.'],
            ]);
        }

        return QuestionnaireTemplate::create([
            'store_id'   => $store->id,
            'name'       => $data['name'],
            'version'    => $version,
            'status'     => $data['status'] ?? 'active',
            'created_by' => $creator->id,
        ]);
    }

    public function update(QuestionnaireTemplate $questionnaire, array $data): QuestionnaireTemplate
    {
        $allowed = collect($data)->only(['name', 'status'])->all();
        $questionnaire->update($allowed);

        return $questionnaire->fresh();
    }

    public function delete(QuestionnaireTemplate $questionnaire): void
    {
        $questionnaire->delete();
    }
}
