<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class CreateDocumentTemplateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'               => ['required', 'string', 'max:255'],
            'document_type'      => ['required', 'string', 'max:100'],
            'requires_signature' => ['sometimes', 'boolean'],
            'description'        => ['nullable', 'string'],
        ];
    }
}
