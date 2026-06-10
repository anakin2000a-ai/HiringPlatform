<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Models\Store;
use App\Models\User;

class DocumentTemplateService
{
    public function create(Store $store, array $data, User $creator): DocumentTemplate
    {
        return DocumentTemplate::create([
            'store_id'           => $store->id,
            'name'               => $data['name'],
            'document_type'      => $data['document_type'],
            'requires_signature' => $data['requires_signature'] ?? false,
            'description'        => $data['description'] ?? null,
            'created_by'         => $creator->id,
        ]);
    }

    public function update(DocumentTemplate $template, array $data): DocumentTemplate
    {
        $allowed = collect($data)->only(['name', 'document_type', 'requires_signature', 'description'])->all();
        $template->update($allowed);

        return $template->fresh();
    }

    public function delete(DocumentTemplate $template): void
    {
        $template->delete();
    }
}
