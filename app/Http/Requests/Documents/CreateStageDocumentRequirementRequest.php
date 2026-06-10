<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class CreateStageDocumentRequirementRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document_template_id'       => ['required', 'integer', 'exists:document_templates,id'],
            'is_required'                => ['sometimes', 'boolean'],
            'due_days_after_stage_entry' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
