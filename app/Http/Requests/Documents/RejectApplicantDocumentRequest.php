<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class RejectApplicantDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rejected_reason' => ['required', 'string'],
        ];
    }
}
