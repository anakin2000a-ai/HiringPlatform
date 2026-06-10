<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStageDocumentRequirementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_required'                => ['sometimes', 'boolean'],
            'due_days_after_stage_entry' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
