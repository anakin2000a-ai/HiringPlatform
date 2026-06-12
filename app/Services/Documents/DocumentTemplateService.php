<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DocumentTemplateService
{
    public function create(Store $store, array $data, User $creator): DocumentTemplate
    {
        $requirements = $data['requirements'] ?? [];

        return DB::transaction(function () use ($store, $data, $creator, $requirements) {
            $template = DocumentTemplate::create([
                'store_id'           => $store->id,
                'name'               => $data['name'],
                'document_type'      => $data['document_type'],
                'requires_signature' => $data['requires_signature'] ?? false,
                'description'        => $data['description'] ?? null,
                'created_by'         => $creator->id,
            ]);

            foreach ($requirements as $req) {
                StageDocumentRequirement::create([
                    'document_template_id'       => $template->id,
                    'workflow_stage_id'          => $req['workflow_stage_id'],
                    'is_required'                => $req['is_required'] ?? true,
                    'due_days_after_stage_entry' => $req['due_days_after_stage_entry'] ?? null,
                ]);
            }

            return $requirements
                ? $template->load('stageRequirements')
                : $template;
        });
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
