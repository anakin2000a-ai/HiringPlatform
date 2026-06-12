<?php

namespace App\Http\Requests\Configuration;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CopyStoreConfigurationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'target_store_id'       => ['required', 'integer', 'exists:stores,id'],
            'copy_workflows'        => ['sometimes', 'boolean'],
            'copy_questionnaires'   => ['sometimes', 'boolean'],
            'copy_documents'        => ['sometimes', 'boolean'],
            'copy_automation_rules' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('target_store_id')) {
                return;
            }

            /** @var Store $sourceStore */
            $sourceStore   = $this->route('store');
            $targetStoreId = (int) $this->input('target_store_id');

            if ($targetStoreId === $sourceStore->id) {
                $validator->errors()->add(
                    'target_store_id',
                    'Target store must be different from the source store.'
                );
            }
        });
    }
}
