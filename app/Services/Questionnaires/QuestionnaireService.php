<?php

namespace App\Services\Questionnaires;

use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionnaireService
{
    public function create(Store $store, array $data, User $creator): QuestionnaireTemplate
    {
        $version   = $data['version'] ?? 1;
        $questions = $data['questions'] ?? [];

        $exists = QuestionnaireTemplate::where('store_id', $store->id)
            ->where('name', $data['name'])
            ->where('version', $version)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => ['A questionnaire with this name and version already exists for this store.'],
            ]);
        }

        if ($questions) {
            $keys      = array_column($questions, 'question_key');
            $positions = array_column($questions, 'position');

            if (count($keys) !== count(array_unique($keys))) {
                throw ValidationException::withMessages(['questions' => ['Question keys must be unique within a questionnaire.']]);
            }

            if (count($positions) !== count(array_unique($positions))) {
                throw ValidationException::withMessages(['questions' => ['Question positions must be unique within a questionnaire.']]);
            }
        }

        return DB::transaction(function () use ($store, $data, $creator, $version, $questions) {
            $questionnaire = QuestionnaireTemplate::create([
                'store_id'   => $store->id,
                'name'       => $data['name'],
                'version'    => $version,
                'status'     => $data['status'] ?? 'active',
                'created_by' => $creator->id,
            ]);

            foreach ($questions as $q) {
                QuestionnaireQuestion::create([
                    'questionnaire_template_id' => $questionnaire->id,
                    'question_key'              => $q['question_key'],
                    'label'                     => $q['label'],
                    'type'                      => $q['type'],
                    'is_required'               => $q['is_required'] ?? false,
                    'position'                  => $q['position'],
                ]);
            }

            return $questions
                ? $questionnaire->load('questions')
                : $questionnaire;
        });
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
