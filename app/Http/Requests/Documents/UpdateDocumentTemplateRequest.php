<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'               => ['sometimes', 'string', 'max:255'],
            'document_type'      => ['sometimes', 'string', 'max:100'],
            'requires_signature' => ['sometimes', 'boolean'],
            'description'        => ['nullable', 'string'],
        ];
    }
}
