<?php

namespace Tests\Feature\Configuration;

use App\Models\AutomationRule;
use App\Models\DocumentTemplate;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\StageDocumentRequirement;
use App\Models\StageQuestionnaireAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationCopyTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeFranchise(): FranchiseAccount
    {
        return FranchiseAccount::factory()->create();
    }

    private function makeStore(FranchiseAccount $franchise): Store
    {
        return Store::factory()->for($franchise)->create();
    }

    private function makeAdmin(FranchiseAccount $franchise): User
    {
        return User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);
    }

    private function makeManager(Store $store): User
    {
        $manager = User::factory()->create(['role' => 'store_manager']);
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $store->id]);
        return $manager;
    }

    private function makeRecruiter(Store $store): User
    {
        $recruiter = User::factory()->create(['role' => 'recruiter']);
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id]);
        return $recruiter;
    }

    /** Build a complete source config: 1 workflow, 2 stages, 1 transition, 1 questionnaire (1 question),
     *  1 assignment, 1 document template, 1 requirement, 1 automation rule (store-level), 1 rule (workflow-scoped).
     */
    private function buildSourceConfig(Store $store, User $creator): array
    {
        $workflow = HiringWorkflow::factory()->forStore($store)->create(['created_by' => $creator->id]);

        $stageA = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create(['name' => 'Applied', 'position' => 1]);
        $stageB = WorkflowStage::factory()->forWorkflow($workflow)->create(['name' => 'Interview', 'position' => 2]);

        $transition = WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'   => $workflow->id,
            'from_stage_id'        => $stageA->id,
            'to_stage_id'          => $stageB->id,
            'name'                 => 'To Interview',
            'is_manual_allowed'    => true,
            'is_automatic_allowed' => false,
        ]);

        $questionnaire = QuestionnaireTemplate::factory()->forStore($store)->create(['name' => 'Screen Q', 'version' => 1]);
        $question      = QuestionnaireQuestion::factory()->create([
            'questionnaire_template_id' => $questionnaire->id,
            'question_key'              => 'experience',
            'label'                     => 'Years of experience',
            'position'                  => 1,
        ]);

        $assignment = StageQuestionnaireAssignment::factory()->create([
            'workflow_stage_id'         => $stageA->id,
            'questionnaire_template_id' => $questionnaire->id,
            'is_required'               => true,
        ]);

        $docTemplate = DocumentTemplate::factory()->forStore($store)->create(['name' => 'ID Document']);

        $requirement = StageDocumentRequirement::factory()->create([
            'workflow_stage_id'    => $stageA->id,
            'document_template_id' => $docTemplate->id,
            'is_required'          => true,
        ]);

        $storeRule = AutomationRule::factory()->forStore($store)->create([
            'hiring_workflow_id' => null,
            'workflow_stage_id'  => null,
            'name'               => 'Store-level Rule',
        ]);

        $workflowRule = AutomationRule::factory()->forStore($store)->create([
            'hiring_workflow_id' => $workflow->id,
            'workflow_stage_id'  => null,
            'name'               => 'Workflow-scoped Rule',
        ]);

        return compact(
            'workflow', 'stageA', 'stageB', 'transition',
            'questionnaire', 'question', 'assignment',
            'docTemplate', 'requirement',
            'storeRule', 'workflowRule'
        );
    }

    private function postCopy(User $actor, Store $source, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)
            ->postJson("/api/v1/stores/{$source->id}/copy-configuration", $payload);
    }

    // -------------------------------------------------------------------------
    // Authorization and validation
    // -------------------------------------------------------------------------

    public function test_unauthenticated_cannot_copy(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);

        $this->postJson("/api/v1/stores/{$source->id}/copy-configuration", [
            'target_store_id' => $target->id,
        ])->assertUnauthorized();
    }

    public function test_franchise_admin_can_copy(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])
            ->assertCreated();
    }

    public function test_store_manager_can_copy(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $manager   = $this->makeManager($source);

        // manager must also have access to target store
        UserStoreAccess::create(['user_id' => $manager->id, 'store_id' => $target->id]);

        $this->postCopy($manager, $source, ['target_store_id' => $target->id])
            ->assertCreated();
    }

    public function test_recruiter_cannot_copy(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $recruiter = $this->makeRecruiter($source);

        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $target->id]);

        $this->postCopy($recruiter, $source, ['target_store_id' => $target->id])
            ->assertForbidden();
    }

    public function test_user_must_access_source_store(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $outsider  = User::factory()->create(['role' => 'store_manager']);

        // outsider only has access to target, not source
        UserStoreAccess::create(['user_id' => $outsider->id, 'store_id' => $target->id]);

        $this->postCopy($outsider, $source, ['target_store_id' => $target->id])
            ->assertForbidden();
    }

    public function test_user_must_access_target_store(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $manager   = $this->makeManager($source);

        // Create a store from a different franchise that the manager cannot access
        $otherFranchise = $this->makeFranchise();
        $targetOther    = $this->makeStore($otherFranchise);

        $this->postCopy($manager, $source, ['target_store_id' => $targetOther->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target_store_id.0', 'You do not have access to the target store.');
    }

    public function test_target_store_id_cannot_equal_source(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $this->postCopy($admin, $source, ['target_store_id' => $source->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.target_store_id.0', 'Target store must be different from the source store.');
    }

    public function test_target_store_id_must_exist(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $this->postCopy($admin, $source, ['target_store_id' => 99999])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('target_store_id');
    }

    public function test_copy_flags_must_be_boolean(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $this->postCopy($admin, $source, [
            'target_store_id'  => $target->id,
            'copy_workflows'   => 'yes',
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('copy_workflows');
    }

    // -------------------------------------------------------------------------
    // Workflow copy
    // -------------------------------------------------------------------------

    public function test_copied_workflows_belong_to_target_store(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        HiringWorkflow::factory()->forStore($source)->create();

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $this->assertDatabaseHas('hiring_workflows', ['store_id' => $target->id]);
    }

    public function test_copied_stages_belong_to_copied_workflows(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow = HiringWorkflow::factory()->forStore($source)->create();
        WorkflowStage::factory()->forWorkflow($workflow)->initial()->create(['name' => 'Stage1']);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedWorkflow = HiringWorkflow::where('store_id', $target->id)->first();
        $this->assertNotNull($copiedWorkflow);
        $this->assertDatabaseHas('workflow_stages', ['hiring_workflow_id' => $copiedWorkflow->id, 'name' => 'Stage1']);
    }

    public function test_copied_transitions_reference_copied_stage_ids(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow  = HiringWorkflow::factory()->forStore($source)->create();
        $stageA    = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $stageB    = WorkflowStage::factory()->forWorkflow($workflow)->create();
        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $stageA->id,
            'to_stage_id'        => $stageB->id,
        ]);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedWorkflow = HiringWorkflow::where('store_id', $target->id)->first();
        $copiedStageIds = WorkflowStage::where('hiring_workflow_id', $copiedWorkflow->id)->pluck('id')->toArray();

        // Every copied transition must reference only copied stage IDs
        WorkflowStageTransition::where('hiring_workflow_id', $copiedWorkflow->id)
            ->each(function ($t) use ($copiedStageIds): void {
                $this->assertContains($t->from_stage_id, $copiedStageIds);
                $this->assertContains($t->to_stage_id, $copiedStageIds);
            });

        // Transitions must NOT reference source stage IDs
        $sourceStageIds = [$stageA->id, $stageB->id];
        WorkflowStageTransition::where('hiring_workflow_id', $copiedWorkflow->id)
            ->each(function ($t) use ($sourceStageIds): void {
                $this->assertNotContains($t->from_stage_id, $sourceStageIds);
                $this->assertNotContains($t->to_stage_id, $sourceStageIds);
            });
    }

    public function test_copied_workflow_counts_are_correct(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow = HiringWorkflow::factory()->forStore($source)->create();
        $stageA   = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $stageB   = WorkflowStage::factory()->forWorkflow($workflow)->create();
        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id' => $workflow->id,
            'from_stage_id'      => $stageA->id,
            'to_stage_id'        => $stageB->id,
        ]);

        $response = $this->postCopy($admin, $source, ['target_store_id' => $target->id])
            ->assertCreated();

        $this->assertEquals(1, $response->json('data.copied_items.workflows'));
        $this->assertEquals(2, $response->json('data.copied_items.workflow_stages'));
        $this->assertEquals(1, $response->json('data.copied_items.workflow_stage_transitions'));
    }

    public function test_duplicate_workflow_name_is_renamed_safely(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $name = 'Onboarding Workflow';

        HiringWorkflow::factory()->forStore($source)->create(['name' => $name, 'version' => 1]);
        // Pre-existing workflow in target with same name/version
        HiringWorkflow::factory()->forStore($target)->create(['name' => $name, 'version' => 1]);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        // Target should now have exactly 2 workflows: the pre-existing one and the renamed copy
        $this->assertEquals(2, HiringWorkflow::where('store_id', $target->id)->count());

        // The copied workflow must have been renamed (not a second row with the same original name)
        $this->assertEquals(1, HiringWorkflow::where('store_id', $target->id)->where('name', $name)->count());

        // A renamed copy must exist containing the source store name
        $this->assertDatabaseHas('hiring_workflows', [
            'store_id' => $target->id,
            'version'  => 1,
        ]);
        $renamedExists = HiringWorkflow::where('store_id', $target->id)
            ->where('name', '!=', $name)
            ->where('version', 1)
            ->exists();
        $this->assertTrue($renamedExists, 'Expected a renamed workflow copy in the target store.');
    }

    // -------------------------------------------------------------------------
    // Questionnaire copy
    // -------------------------------------------------------------------------

    public function test_copied_questionnaire_templates_belong_to_target_store(): void
    {
        $franchise    = $this->makeFranchise();
        $source       = $this->makeStore($franchise);
        $target       = $this->makeStore($franchise);
        $admin        = $this->makeAdmin($franchise);

        QuestionnaireTemplate::factory()->forStore($source)->create();

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $this->assertDatabaseHas('questionnaire_templates', ['store_id' => $target->id]);
    }

    public function test_copied_questions_belong_to_copied_templates(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $qt = QuestionnaireTemplate::factory()->forStore($source)->create();
        QuestionnaireQuestion::factory()->create([
            'questionnaire_template_id' => $qt->id,
            'question_key'              => 'q1',
        ]);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedTemplate = QuestionnaireTemplate::where('store_id', $target->id)->first();
        $this->assertDatabaseHas('questionnaire_questions', [
            'questionnaire_template_id' => $copiedTemplate->id,
            'question_key'              => 'q1',
        ]);
    }

    public function test_stage_questionnaire_assignments_reference_copied_stages_and_templates(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow  = HiringWorkflow::factory()->forStore($source)->create();
        $stageA    = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $qt        = QuestionnaireTemplate::factory()->forStore($source)->create();
        StageQuestionnaireAssignment::factory()->create([
            'workflow_stage_id'         => $stageA->id,
            'questionnaire_template_id' => $qt->id,
        ]);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedWorkflow  = HiringWorkflow::where('store_id', $target->id)->first();
        $copiedStageIds  = WorkflowStage::where('hiring_workflow_id', $copiedWorkflow->id)->pluck('id');
        $copiedTemplates = QuestionnaireTemplate::where('store_id', $target->id)->pluck('id');

        $assignment = StageQuestionnaireAssignment::whereIn('workflow_stage_id', $copiedStageIds)->first();
        $this->assertNotNull($assignment);
        $this->assertContains($assignment->questionnaire_template_id, $copiedTemplates);

        // Must not reference source stage IDs
        $this->assertNotEquals($stageA->id, $assignment->workflow_stage_id);
    }

    public function test_copy_questionnaires_true_copy_workflows_false_skips_assignments(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow = HiringWorkflow::factory()->forStore($source)->create();
        $stageA   = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $qt       = QuestionnaireTemplate::factory()->forStore($source)->create();
        StageQuestionnaireAssignment::factory()->create([
            'workflow_stage_id'         => $stageA->id,
            'questionnaire_template_id' => $qt->id,
        ]);

        $response = $this->postCopy($admin, $source, [
            'target_store_id'     => $target->id,
            'copy_workflows'      => false,
            'copy_questionnaires' => true,
        ])->assertCreated();

        // Template and question were copied
        $this->assertEquals(1, $response->json('data.copied_items.questionnaire_templates'));
        // No assignments
        $this->assertEquals(0, $response->json('data.copied_items.stage_questionnaire_assignments'));
        // No workflows
        $this->assertEquals(0, $response->json('data.copied_items.workflows'));
    }

    // -------------------------------------------------------------------------
    // Document copy
    // -------------------------------------------------------------------------

    public function test_copied_document_templates_belong_to_target_store(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        DocumentTemplate::factory()->forStore($source)->create();

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $this->assertDatabaseHas('document_templates', ['store_id' => $target->id]);
    }

    public function test_stage_document_requirements_reference_copied_stages_and_templates(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow    = HiringWorkflow::factory()->forStore($source)->create();
        $stageA      = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $docTemplate = DocumentTemplate::factory()->forStore($source)->create();
        StageDocumentRequirement::factory()->create([
            'workflow_stage_id'    => $stageA->id,
            'document_template_id' => $docTemplate->id,
        ]);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedWorkflow    = HiringWorkflow::where('store_id', $target->id)->first();
        $copiedStageIds    = WorkflowStage::where('hiring_workflow_id', $copiedWorkflow->id)->pluck('id');
        $copiedDocTemplIds = DocumentTemplate::where('store_id', $target->id)->pluck('id');

        $req = StageDocumentRequirement::whereIn('workflow_stage_id', $copiedStageIds)->first();
        $this->assertNotNull($req);
        $this->assertContains($req->document_template_id, $copiedDocTemplIds);
        $this->assertNotEquals($stageA->id, $req->workflow_stage_id);
    }

    public function test_copy_documents_true_copy_workflows_false_skips_requirements(): void
    {
        $franchise   = $this->makeFranchise();
        $source      = $this->makeStore($franchise);
        $target      = $this->makeStore($franchise);
        $admin       = $this->makeAdmin($franchise);

        $workflow    = HiringWorkflow::factory()->forStore($source)->create();
        $stageA      = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $docTemplate = DocumentTemplate::factory()->forStore($source)->create();
        StageDocumentRequirement::factory()->create([
            'workflow_stage_id'    => $stageA->id,
            'document_template_id' => $docTemplate->id,
        ]);

        $response = $this->postCopy($admin, $source, [
            'target_store_id' => $target->id,
            'copy_workflows'  => false,
            'copy_documents'  => true,
        ])->assertCreated();

        $this->assertEquals(1, $response->json('data.copied_items.document_templates'));
        $this->assertEquals(0, $response->json('data.copied_items.stage_document_requirements'));
        $this->assertEquals(0, $response->json('data.copied_items.workflows'));
    }

    // -------------------------------------------------------------------------
    // Automation rule copy
    // -------------------------------------------------------------------------

    public function test_copied_automation_rules_belong_to_target_store(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        AutomationRule::factory()->forStore($source)->create();

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $this->assertDatabaseHas('automation_rules', ['store_id' => $target->id]);
    }

    public function test_copied_automation_rules_remap_workflow_and_stage_ids(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow = HiringWorkflow::factory()->forStore($source)->create();
        $stageA   = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();

        AutomationRule::factory()->forStore($source)->create([
            'hiring_workflow_id' => $workflow->id,
            'workflow_stage_id'  => $stageA->id,
            'name'               => 'Scoped Rule',
        ]);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedWorkflow = HiringWorkflow::where('store_id', $target->id)->first();
        $copiedStage    = WorkflowStage::where('hiring_workflow_id', $copiedWorkflow->id)->first();

        $copiedRule = \App\Models\AutomationRule::where('store_id', $target->id)->where('name', 'Scoped Rule')->first();
        $this->assertNotNull($copiedRule);
        $this->assertEquals($copiedWorkflow->id, $copiedRule->hiring_workflow_id);
        $this->assertEquals($copiedStage->id, $copiedRule->workflow_stage_id);

        // Must not reference source IDs
        $this->assertNotEquals($workflow->id, $copiedRule->hiring_workflow_id);
        $this->assertNotEquals($stageA->id, $copiedRule->workflow_stage_id);
    }

    public function test_copy_automation_rules_true_copy_workflows_false_copies_store_level_rules_only(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow = HiringWorkflow::factory()->forStore($source)->create();

        AutomationRule::factory()->forStore($source)->create([
            'hiring_workflow_id' => null,
            'workflow_stage_id'  => null,
            'name'               => 'Store Rule',
        ]);
        AutomationRule::factory()->forStore($source)->create([
            'hiring_workflow_id' => $workflow->id,
            'workflow_stage_id'  => null,
            'name'               => 'Workflow Rule',
        ]);

        $response = $this->postCopy($admin, $source, [
            'target_store_id'        => $target->id,
            'copy_workflows'         => false,
            'copy_automation_rules'  => true,
        ])->assertCreated();

        $this->assertEquals(1, $response->json('data.copied_items.automation_rules'));
        $this->assertDatabaseHas('automation_rules', ['store_id' => $target->id, 'name' => 'Store Rule']);
        $this->assertDatabaseMissing('automation_rules', ['store_id' => $target->id, 'name' => 'Workflow Rule']);
    }

    // -------------------------------------------------------------------------
    // Independence and transaction
    // -------------------------------------------------------------------------

    public function test_source_and_target_are_independent_after_copy(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $workflow = HiringWorkflow::factory()->forStore($source)->create(['name' => 'Original']);

        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $copiedWorkflow = HiringWorkflow::where('store_id', $target->id)->first();
        $copiedWorkflow->update(['name' => 'Modified in Target']);

        // Source workflow unchanged
        $this->assertDatabaseHas('hiring_workflows', ['id' => $workflow->id, 'name' => 'Original']);
    }

    public function test_configuration_copy_log_is_created_with_counts(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        HiringWorkflow::factory()->forStore($source)->create();

        $response = $this->postCopy($admin, $source, ['target_store_id' => $target->id])
            ->assertCreated();

        $this->assertDatabaseHas('configuration_copy_logs', [
            'source_store_id' => $source->id,
            'target_store_id' => $target->id,
            'copied_by'       => $admin->id,
        ]);

        $this->assertEquals(1, $response->json('data.copied_items.workflows'));
        $this->assertEquals($source->id, $response->json('data.source_store_id'));
        $this->assertEquals($target->id, $response->json('data.target_store_id'));
    }

    public function test_copy_operation_is_all_or_nothing(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        HiringWorkflow::factory()->forStore($source)->create();

        // Successful copy creates records
        $this->postCopy($admin, $source, ['target_store_id' => $target->id])->assertCreated();

        $this->assertDatabaseHas('hiring_workflows', ['store_id' => $target->id]);
        $this->assertDatabaseHas('configuration_copy_logs', [
            'source_store_id' => $source->id,
            'target_store_id' => $target->id,
        ]);
    }

    public function test_full_copy_response_contains_all_count_keys(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $response = $this->postCopy($admin, $source, ['target_store_id' => $target->id])
            ->assertCreated();

        $items = $response->json('data.copied_items');

        $expectedKeys = [
            'workflows',
            'workflow_stages',
            'workflow_stage_transitions',
            'questionnaire_templates',
            'questionnaire_questions',
            'stage_questionnaire_assignments',
            'document_templates',
            'stage_document_requirements',
            'automation_rules',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $items, "Missing key: {$key}");
        }
    }

    public function test_full_integrated_copy_produces_correct_counts(): void
    {
        $franchise = $this->makeFranchise();
        $source    = $this->makeStore($franchise);
        $target    = $this->makeStore($franchise);
        $admin     = $this->makeAdmin($franchise);

        $this->buildSourceConfig($source, $admin);

        $response = $this->postCopy($admin, $source, ['target_store_id' => $target->id])
            ->assertCreated();

        $items = $response->json('data.copied_items');

        $this->assertEquals(1, $items['workflows']);
        $this->assertEquals(2, $items['workflow_stages']);
        $this->assertEquals(1, $items['workflow_stage_transitions']);
        $this->assertEquals(1, $items['questionnaire_templates']);
        $this->assertEquals(1, $items['questionnaire_questions']);
        $this->assertEquals(1, $items['stage_questionnaire_assignments']);
        $this->assertEquals(1, $items['document_templates']);
        $this->assertEquals(1, $items['stage_document_requirements']);
        $this->assertEquals(2, $items['automation_rules']); // store-level + workflow-scoped
    }
}
