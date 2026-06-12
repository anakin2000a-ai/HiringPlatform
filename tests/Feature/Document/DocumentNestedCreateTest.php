<?php

namespace Tests\Feature\Document;

use App\Models\DocumentTemplate;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\StageDocumentRequirement;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNestedCreateTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeStore(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();
        $admin     = User::factory()->create();
        UserStoreAccess::create(['user_id' => $admin->id, 'store_id' => $store->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        return [$franchise, $store, $admin];
    }

    private function makeStage(Store $store): WorkflowStage
    {
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        return WorkflowStage::factory()->forWorkflow($workflow)->create(['position' => 1]);
    }

    private function actingAsWithAccess(User $admin, Store $store): static
    {
        UserStoreAccess::firstOrCreate(['user_id' => $admin->id, 'store_id' => $store->id]);

        return $this->actingAs($admin);
    }

    private function url(Store $store): string
    {
        return "/api/v1/stores/{$store->id}/document-templates";
    }

    // -----------------------------------------------------------------------
    // Without requirements — existing behaviour preserved
    // -----------------------------------------------------------------------

    public function test_create_document_template_without_requirements_succeeds(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Employment Contract',
                'document_type' => 'contract',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.name', 'Employment Contract');
        $response->assertJsonMissing(['data.requirements']);

        $this->assertDatabaseHas('document_templates', ['name' => 'Employment Contract', 'store_id' => $store->id]);
        $this->assertSame(0, StageDocumentRequirement::count());
    }

    // -----------------------------------------------------------------------
    // With requirements
    // -----------------------------------------------------------------------

    public function test_create_document_template_with_nested_requirements_creates_both(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage1            = $this->makeStage($store);
        $stage2            = $this->makeStage($store);

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'National ID',
                'document_type' => 'identity',
                'requirements'  => [
                    [
                        'workflow_stage_id'          => $stage1->id,
                        'is_required'                => true,
                        'due_days_after_stage_entry' => 7,
                    ],
                    [
                        'workflow_stage_id'          => $stage2->id,
                        'is_required'                => false,
                        'due_days_after_stage_entry' => null,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $template = DocumentTemplate::where('name', 'National ID')->firstOrFail();
        $this->assertSame($store->id, $template->store_id);
        $this->assertSame(2, $template->stageRequirements()->count());

        $req1 = $template->stageRequirements()->where('workflow_stage_id', $stage1->id)->first();
        $this->assertNotNull($req1);
        $this->assertTrue((bool) $req1->is_required);
        $this->assertSame(7, $req1->due_days_after_stage_entry);

        $req2 = $template->stageRequirements()->where('workflow_stage_id', $stage2->id)->first();
        $this->assertNotNull($req2);
        $this->assertFalse((bool) $req2->is_required);
    }

    public function test_response_includes_requirements_when_provided(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage             = $this->makeStage($store);

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Contract With Req',
                'document_type' => 'contract',
                'requirements'  => [
                    ['workflow_stage_id' => $stage->id, 'is_required' => true],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'data.requirements');
        $response->assertJsonPath('data.requirements.0.workflow_stage_id', $stage->id);
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    public function test_missing_workflow_stage_id_fails_validation(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Bad Req',
                'document_type' => 'contract',
                'requirements'  => [
                    ['is_required' => true],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['requirements.0.workflow_stage_id']);
    }

    public function test_nonexistent_workflow_stage_id_fails_validation(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Bad Stage',
                'document_type' => 'contract',
                'requirements'  => [
                    ['workflow_stage_id' => 999999, 'is_required' => true],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['requirements.0.workflow_stage_id']);
    }

    public function test_stage_from_different_store_is_rejected(): void
    {
        [, $store, $admin] = $this->makeStore();

        // Stage belongs to a different store
        [$franchise2] = $this->makeStore();
        $store2        = Store::factory()->for($franchise2)->create();
        $stageOther    = $this->makeStage($store2);

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Cross Store',
                'document_type' => 'contract',
                'requirements'  => [
                    ['workflow_stage_id' => $stageOther->id, 'is_required' => true],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_workflow_stage_ids_fail_validation(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage             = $this->makeStage($store);

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Dup Stages',
                'document_type' => 'contract',
                'requirements'  => [
                    ['workflow_stage_id' => $stage->id, 'is_required' => true],
                    ['workflow_stage_id' => $stage->id, 'is_required' => false],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_negative_due_days_fails_validation(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage             = $this->makeStage($store);

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Neg Days',
                'document_type' => 'contract',
                'requirements'  => [
                    [
                        'workflow_stage_id'          => $stage->id,
                        'due_days_after_stage_entry' => -1,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['requirements.0.due_days_after_stage_entry']);
    }

    // -----------------------------------------------------------------------
    // Rollback
    // -----------------------------------------------------------------------

    public function test_transaction_rolls_back_when_requirement_data_is_invalid(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage             = $this->makeStage($store);

        // Second requirement has missing workflow_stage_id — validation catches before DB
        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'          => 'Rollback Doc',
                'document_type' => 'contract',
                'requirements'  => [
                    ['workflow_stage_id' => $stage->id, 'is_required' => true],
                    ['is_required' => false],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('document_templates', ['name' => 'Rollback Doc']);
        $this->assertSame(0, StageDocumentRequirement::count());
    }

    // -----------------------------------------------------------------------
    // Unauthorized
    // -----------------------------------------------------------------------

    public function test_unauthenticated_request_rejected(): void
    {
        [, $store] = $this->makeStore();

        $this->postJson($this->url($store), [
            'name'          => 'T',
            'document_type' => 'contract',
        ])->assertStatus(401);
    }

    public function test_user_without_store_access_rejected(): void
    {
        [, $store]    = $this->makeStore();
        $franchise2   = FranchiseAccount::factory()->create();
        $otherStore   = Store::factory()->for($franchise2)->create();
        $outsider     = User::factory()->create();
        UserStoreAccess::create(['user_id' => $outsider->id, 'store_id' => $otherStore->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $this->actingAs($outsider)
            ->postJson($this->url($store), [
                'name'          => 'T',
                'document_type' => 'contract',
            ])->assertStatus(403);
    }
}
