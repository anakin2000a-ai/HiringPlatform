<?php

namespace App\Http\Requests\Questionnaires;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\ApplicantAnswer;
use Illuminate\Contracts\Validation\Validator;
class SubmitApplicantAnswersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'questionnaire_template_id'             => ['required', 'integer', 'exists:questionnaire_templates,id'],
            'answers'                               => ['required', 'array', 'min:1'],
            'answers.*.questionnaire_question_id'   => ['required', 'integer', 'exists:questionnaire_questions,id'],
            'answers.*.answer'                      => ['present', 'nullable'],
        ];
    } public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $application = $this->route('application');

            foreach ($this->input('answers', []) as $index => $answer) {
                $questionId = $answer['questionnaire_question_id'] ?? null;

                if (! $questionId) {
                    continue;
                }

                $exists = ApplicantAnswer::where('application_id', $application->id)
                    ->where('questionnaire_template_id', $this->input('questionnaire_template_id'))
                    ->where('questionnaire_question_id', $questionId)
                    ->exists();

                if ($exists) {
                    $v->errors()->add(
                        "answers.$index.questionnaire_question_id",
                        'This question has already been answered.'
                    );
                }
            }
        });
    }
    
}
