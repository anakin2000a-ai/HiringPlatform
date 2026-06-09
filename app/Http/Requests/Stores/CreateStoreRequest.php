<?php

namespace App\Http\Requests\Stores;

use Illuminate\Foundation\Http\FormRequest;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role check handled in controller
    }

    public function rules(): array
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
        ];
    }
}
