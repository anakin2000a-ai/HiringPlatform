<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class InitiateApplicantDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'stage_document_requirement_id' => ['required', 'integer', 'exists:stage_document_requirements,id'],
        ];
    }
}
