<?php

namespace App\Http\Requests\Applications;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ApplyToJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['required', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $email = $this->input('email');
            $phone = $this->input('phone');

            if (empty($email) && empty($phone)) {
                $v->errors()->add('email', 'At least one of email or phone is required.');
            }
        });
    }
}
