<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class SignApplicantDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'external_signature_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
