<?php

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

class MoveApplicationStageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'to_stage_id' => ['required', 'integer', 'exists:workflow_stages,id'],
            'reason'      => ['nullable', 'string', 'max:500'],
        ];
    }
}
