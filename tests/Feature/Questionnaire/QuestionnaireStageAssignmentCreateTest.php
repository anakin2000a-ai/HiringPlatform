<?php

namespace Tests\Feature\Questionnaire;

use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\QuestionnaireTemplate;
use App\Models\StageQuestionnaireAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionnaireStageAssignmentCreateTest extends TestCase
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
        UserStoreAccess::create([
            'user_id'      => $admin->id,
            'store_id'     => $store->id,
            'role'         => 'franchise_admin',
            'access_scope' => 'franchise',
            'status'       => 'active',
        ]);

        return [$franchise, $store, $admin];
    }

    private function makeStage(Store $store): WorkflowStage
    {
        $workflow = HiringWorkflow::factory()->forStore($store)->create();

        return WorkflowStage::factory()->forWorkflow($workflow)->create();
    }

    private function url(Store $store): string
    {
        return "/api/v1/stores/{$store->store_name}/questionnaires";
    }

    // -----------------------------------------------------------------------
    // 1. Creating questionnaire with questions only (no stage_assignment)
    // -----------------------------------------------------------------------

    public function test_create_questionnaire_with_questions_only(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAs($admin)->postJson($this->url($store), [
            'name'   => 'Skills Assessment',
            'status' => 'active',
            'questions' => [
                [
                    'question_key' => 'exp',
                    'label'        => 'Years of Experience',
                    'type'         => 'number',
                    'is_required'  => true,
                    'position'     => 1,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Skills Assessment')
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.question_key', 'exp');

        $this->assertDatabaseHas('questionnaire_templates', [
            'name'     => 'Skills Assessment',
            'store_id' => $store->id,
        ]);
        $this->assertSame(0, StageQuestionnaireAssignment::count());
    }

    // -----------------------------------------------------------------------
    // 2. Creating questionnaire with questions + stage_assignment
    // -----------------------------------------------------------------------

    public function test_create_questionnaire_with_questions_and_stage_assignment(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage = $this->makeStage($store);

        $response = $this->actingAs($admin)->postJson($this->url($store), [
            'name'   => 'General Application Form',
            'version' => 1,
            'status' => 'active',
            'questions' => [
                [
                    'question_key' => 'exp',
                    'label'        => 'Years of Experience',
                    'type'         => 'number',
                    'is_required'  => true,
                    'position'     => 1,
                ],
            ],
            'stage_assignment' => [
                'workflow_stage_id' => $stage->id,
                'is_required'       => true,
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'General Application Form')
            ->assertJsonCount(1, 'data.questions');

        $questionnaire = QuestionnaireTemplate::where('name', 'General Application Form')->firstOrFail();

        $this->assertDatabaseHas('stage_questionnaire_assignments', [
            'workflow_stage_id'         => $stage->id,
            'questionnaire_template_id' => $questionnaire->id,
            'is_required'               => true,
        ]);

        // Response includes stage_assignments
        $response->assertJsonPath('data.stage_assignments.0.workflow_stage_id', $stage->id);
    }

    // -----------------------------------------------------------------------
    // 3. stage_assignment rejected when stage belongs to another store
    // -----------------------------------------------------------------------

    public function test_stage_assignment_rejected_when_stage_belongs_to_another_store(): void
    {
        [, $store, $admin] = $this->makeStore();

        // Stage from a different store
        $otherFranchise = FranchiseAccount::factory()->create();
        $otherStore     = Store::factory()->for($otherFranchise)->create();
        $stageOfOther   = $this->makeStage($otherStore);

        $response = $this->actingAs($admin)->postJson($this->url($store), [
            'name' => 'Cross-store Assignment',
            'stage_assignment' => [
                'workflow_stage_id' => $stageOfOther->id,
                'is_required'       => true,
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['stage_assignment.workflow_stage_id']);

        $this->assertDatabaseMissing('questionnaire_templates', ['name' => 'Cross-store Assignment']);
        $this->assertSame(0, StageQuestionnaireAssignment::count());
    }

    // -----------------------------------------------------------------------
    // 4. Duplicate stage assignment rejected
    //
    // The creation endpoint always produces a new template (blocked by name+version
    // uniqueness otherwise), so template-level duplicates are prevented upstream.
    // The assignment-level duplicate guard (same stage + same template) is exercised
    // by the separate assignment endpoint, which shares the same service logic.
    // Here we verify both guards via the two distinct code paths:
    //   a) Attempting to re-create the same questionnaire name+version → rejected by
    //      the name-uniqueness guard before the assignment is even attempted.
    //   b) Attempting to re-assign an existing template to the same stage via the
    //      dedicated assignment endpoint → rejected by the duplicate-assignment guard.
    // -----------------------------------------------------------------------

    public function test_duplicate_questionnaire_name_version_prevents_re_assignment(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage = $this->makeStage($store);

        // First creation with stage_assignment succeeds
        $this->actingAs($admin)->postJson($this->url($store), [
            'name'    => 'Unique Q',
            'version' => 1,
            'stage_assignment' => [
                'workflow_stage_id' => $stage->id,
                'is_required'       => true,
            ],
        ])->assertCreated();

        $this->assertSame(1, StageQuestionnaireAssignment::count());

        // Second attempt with identical name+version (same stage) is blocked by
        // the pre-existing questionnaire uniqueness check, never reaching the assignment.
        $this->actingAs($admin)->postJson($this->url($store), [
            'name'    => 'Unique Q',
            'version' => 1,
            'stage_assignment' => [
                'workflow_stage_id' => $stage->id,
                'is_required'       => true,
            ],
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['name']);

        // Assignment count is still 1 — no second assignment was created
        $this->assertSame(1, StageQuestionnaireAssignment::count());
    }

    public function test_duplicate_stage_assignment_rejected_via_assignment_endpoint(): void
    {
        [, $store, $admin] = $this->makeStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $stage    = WorkflowStage::factory()->forWorkflow($workflow)->create();
        $q        = QuestionnaireTemplate::factory()->forStore($store)->create();

        $assignUrl = "/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/stages/{$stage->id}/questionnaires";

        // First assignment succeeds
        $this->actingAs($admin)
            ->postJson($assignUrl, ['questionnaire_template_id' => $q->id, 'is_required' => true])
            ->assertCreated();

        $this->assertSame(1, StageQuestionnaireAssignment::count());

        // Second assignment to same stage+template is rejected
        $this->actingAs($admin)
            ->postJson($assignUrl, ['questionnaire_template_id' => $q->id, 'is_required' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['questionnaire_template_id']);

        // Still only one assignment
        $this->assertSame(1, StageQuestionnaireAssignment::count());
    }

    // -----------------------------------------------------------------------
    // 5. stage_assignment is optional — omitting it succeeds normally
    // -----------------------------------------------------------------------

    public function test_stage_assignment_is_optional(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAs($admin)->postJson($this->url($store), [
            'name'   => 'No Assignment Q',
            'status' => 'active',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'No Assignment Q');

        $this->assertSame(0, StageQuestionnaireAssignment::count());
    }

    // -----------------------------------------------------------------------
    // stage_assignment.workflow_stage_id required when stage_assignment present
    // -----------------------------------------------------------------------

    public function test_stage_assignment_without_workflow_stage_id_fails(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAs($admin)->postJson($this->url($store), [
            'name'             => 'Missing Stage ID',
            'stage_assignment' => ['is_required' => true],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['stage_assignment.workflow_stage_id']);
    }

    // -----------------------------------------------------------------------
    // stage_assignment.is_required defaults to true when omitted
    // -----------------------------------------------------------------------

    public function test_stage_assignment_is_required_defaults_to_true(): void
    {
        [, $store, $admin] = $this->makeStore();
        $stage = $this->makeStage($store);

        $this->actingAs($admin)->postJson($this->url($store), [
            'name'             => 'Default Required',
            'stage_assignment' => ['workflow_stage_id' => $stage->id],
        ])->assertCreated();

        $this->assertDatabaseHas('stage_questionnaire_assignments', [
            'workflow_stage_id' => $stage->id,
            'is_required'       => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Existing separate assignment endpoint is unaffected
    // -----------------------------------------------------------------------

    public function test_separate_stage_assignment_endpoint_still_works(): void
    {
        [, $store, $admin] = $this->makeStore();
        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $stage    = WorkflowStage::factory()->forWorkflow($workflow)->create();
        $q        = QuestionnaireTemplate::factory()->forStore($store)->create();

        $this->actingAs($admin)
            ->postJson(
                "/api/v1/stores/{$store->store_name}/workflows/{$workflow->id}/stages/{$stage->id}/questionnaires",
                [
                    'questionnaire_template_id' => $q->id,
                    'is_required'               => false,
                ]
            )
            ->assertCreated()
            ->assertJsonPath('data.workflow_stage_id', $stage->id)
            ->assertJsonPath('data.questionnaire_template_id', $q->id);
    }
}
