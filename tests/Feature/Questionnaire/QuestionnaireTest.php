<?php

namespace Tests\Feature\Questionnaire;

use App\Models\Application;
use App\Models\ApplicantAnswer;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\StageQuestionnaireAssignment;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use App\Models\WorkflowStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionnaireTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeStore(): array
    {
        $franchise = FranchiseAccount::factory()->create();
        $store     = Store::factory()->for($franchise)->create();
        $admin     = User::factory()->franchiseAdmin()->create(['franchise_account_id' => $franchise->id]);

        return [$franchise, $store, $admin];
    }

    private function makeQuestionnaire(Store $store, array $overrides = []): QuestionnaireTemplate
    {
        return QuestionnaireTemplate::factory()->forStore($store)->create($overrides);
    }

    private function makeQuestion(QuestionnaireTemplate $questionnaire, array $overrides = []): QuestionnaireQuestion
    {
        return QuestionnaireQuestion::factory()->forQuestionnaire($questionnaire)->create($overrides);
    }

    private function makeApplication(Store $store): array
    {
        $workflow     = HiringWorkflow::factory()->forStore($store)->create();
        $initialStage = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();
        $job          = JobOpening::factory()->withWorkflow($workflow)->published()->create();
        $application  = Application::factory()->atStage($initialStage)->create(['job_opening_id' => $job->id]);

        return [$workflow, $initialStage, $job, $application];
    }

    // -----------------------------------------------------------------------
    // Questionnaire template — CRUD
    // -----------------------------------------------------------------------

    public function test_can_create_questionnaire_template(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires", [
                'name' => 'Basic Screening',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Basic Screening')
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('questionnaire_templates', [
            'store_id' => $store->id,
            'name'     => 'Basic Screening',
        ]);
    }

    public function test_create_sets_created_by_to_authenticated_user(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires", ['name' => 'Test Q'])
            ->assertCreated();

        $this->assertDatabaseHas('questionnaire_templates', [
            'store_id'   => $store->id,
            'created_by' => $admin->id,
        ]);
    }

    public function test_cannot_create_questionnaire_with_duplicate_name_and_version(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->makeQuestionnaire($store, ['name' => 'Duplicate Q', 'version' => 1]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires", ['name' => 'Duplicate Q'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A questionnaire with this name and version already exists for this store.');
    }

    public function test_can_list_questionnaire_templates(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->makeQuestionnaire($store);
        $this->makeQuestionnaire($store);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_list_is_scoped_to_route_store(): void
    {
        [$franchise, $storeA, $admin] = $this->makeStore();
        $storeB = Store::factory()->for($franchise)->create();

        $this->makeQuestionnaire($storeA);
        $this->makeQuestionnaire($storeB);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/questionnaires")
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_can_show_questionnaire_with_questions(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $this->makeQuestion($questionnaire);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data.questions'));
    }

    public function test_show_returns_404_for_wrong_store(): void
    {
        [$franchise, $storeA, $admin] = $this->makeStore();
        $storeB        = Store::factory()->for($franchise)->create();
        $questionnaire = $this->makeQuestionnaire($storeB);

        $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/questionnaires/{$questionnaire->id}")
            ->assertNotFound();
    }

    public function test_can_update_questionnaire_template(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}", [
                'name' => 'Updated Name',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_can_delete_questionnaire_template(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}")
            ->assertOk();

        $this->assertDatabaseMissing('questionnaire_templates', ['id' => $questionnaire->id]);
    }

    // -----------------------------------------------------------------------
    // Questionnaire template — authorization
    // -----------------------------------------------------------------------

    public function test_cannot_create_questionnaire_for_inaccessible_store(): void
    {
        [$franchise, $store] = $this->makeStore();
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires", ['name' => 'Q'])
            ->assertForbidden();
    }

    public function test_recruiter_cannot_create_questionnaire(): void
    {
        [$franchise, $store] = $this->makeStore();
        $recruiter = User::factory()->recruiter()->create(['franchise_account_id' => $franchise->id]);
        UserStoreAccess::create(['user_id' => $recruiter->id, 'store_id' => $store->id]);

        $this->actingAs($recruiter)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires", ['name' => 'Q'])
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_questionnaire_routes(): void
    {
        [, $store] = $this->makeStore();

        $this->getJson("/api/v1/stores/{$store->id}/questionnaires")
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // Questions — CRUD
    // -----------------------------------------------------------------------

    public function test_can_create_questionnaire_question(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions", [
                'question_key' => 'work_authorization',
                'label'        => 'Are you legally allowed to work?',
                'type'         => 'boolean',
                'is_required'  => true,
                'position'     => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.question_key', 'work_authorization')
            ->assertJsonPath('data.type', 'boolean')
            ->assertJsonPath('data.is_required', true);

        $this->assertDatabaseHas('questionnaire_questions', [
            'questionnaire_template_id' => $questionnaire->id,
            'question_key'              => 'work_authorization',
        ]);
    }

    public function test_question_key_is_unique_per_questionnaire(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $this->makeQuestion($questionnaire, ['question_key' => 'my_key']);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions", [
                'question_key' => 'my_key',
                'label'        => 'Duplicate key question',
                'type'         => 'text',
                'position'     => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.question_key.0', 'A question with this key already exists in this questionnaire.');
    }

    public function test_question_position_can_be_reused_in_different_questionnaire(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaireA = $this->makeQuestionnaire($store);
        $questionnaireB = $this->makeQuestionnaire($store);

        $this->makeQuestion($questionnaireA, ['position' => 1, 'question_key' => 'q_a1']);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaireB->id}/questions", [
                'question_key' => 'q_b1',
                'label'        => 'Same position, different questionnaire',
                'type'         => 'text',
                'position'     => 1,
            ])
            ->assertCreated();
    }

    public function test_can_list_questions_for_questionnaire(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $this->makeQuestion($questionnaire, ['question_key' => 'q1', 'position' => 1]);
        $this->makeQuestion($questionnaire, ['question_key' => 'q2', 'position' => 2]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_update_questionnaire_question(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $question      = $this->makeQuestion($questionnaire);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions/{$question->id}", [
                'label' => 'Updated label',
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Updated label');
    }

    public function test_can_delete_questionnaire_question(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $question      = $this->makeQuestion($questionnaire);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions/{$question->id}")
            ->assertOk();

        $this->assertDatabaseMissing('questionnaire_questions', ['id' => $question->id]);
    }

    public function test_question_from_different_questionnaire_returns_404(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaireA = $this->makeQuestionnaire($store);
        $questionnaireB = $this->makeQuestionnaire($store);
        $questionInB    = $this->makeQuestion($questionnaireB);

        $this->actingAs($admin)
            ->patchJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaireA->id}/questions/{$questionInB->id}", [
                'label' => 'Hacked',
            ])
            ->assertNotFound();
    }

    public function test_all_question_types_are_accepted(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $types = ['text', 'number', 'boolean', 'date', 'select', 'multiselect'];

        foreach ($types as $i => $type) {
            $this->actingAs($admin)
                ->postJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions", [
                    'question_key' => "q_{$type}",
                    'label'        => "Question of type {$type}",
                    'type'         => $type,
                    'position'     => $i + 1,
                ])
                ->assertCreated();
        }
    }

    public function test_invalid_question_type_is_rejected(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/questionnaires/{$questionnaire->id}/questions", [
                'question_key' => 'bad_type',
                'label'        => 'Bad type question',
                'type'         => 'textarea',
                'position'     => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['type']]);
    }

    // -----------------------------------------------------------------------
    // Stage questionnaire assignment
    // -----------------------------------------------------------------------

    public function test_can_assign_questionnaire_to_workflow_stage(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $workflow      = HiringWorkflow::factory()->forStore($store)->create();
        $stage         = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages/{$stage->id}/questionnaires", [
                'questionnaire_template_id' => $questionnaire->id,
                'is_required'               => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.workflow_stage_id', $stage->id)
            ->assertJsonPath('data.questionnaire_template_id', $questionnaire->id)
            ->assertJsonPath('data.is_required', true);

        $this->assertDatabaseHas('stage_questionnaire_assignments', [
            'workflow_stage_id'         => $stage->id,
            'questionnaire_template_id' => $questionnaire->id,
        ]);
    }

    public function test_cannot_assign_questionnaire_from_another_store(): void
    {
        [$franchise, $store, $admin] = $this->makeStore();
        $storeB        = Store::factory()->for($franchise)->create();
        $questionnaireB = $this->makeQuestionnaire($storeB);

        $workflow = HiringWorkflow::factory()->forStore($store)->create();
        $stage    = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages/{$stage->id}/questionnaires", [
                'questionnaire_template_id' => $questionnaireB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.questionnaire_template_id.0', 'The questionnaire template does not belong to this store.');
    }

    public function test_cannot_assign_questionnaire_to_stage_from_another_store(): void
    {
        [$franchise, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $storeB    = Store::factory()->for($franchise)->create();
        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB    = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();

        // Use store route but stage belongs to storeB's workflow — workflow not found
        $workflowA = HiringWorkflow::factory()->forStore($store)->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflowA->id}/stages/{$stageB->id}/questionnaires", [
                'questionnaire_template_id' => $questionnaire->id,
            ])
            ->assertNotFound();
    }

    public function test_cannot_assign_same_questionnaire_twice_to_same_stage(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $workflow      = HiringWorkflow::factory()->forStore($store)->create();
        $stage         = WorkflowStage::factory()->forWorkflow($workflow)->initial()->create();

        StageQuestionnaireAssignment::factory()->create([
            'workflow_stage_id'         => $stage->id,
            'questionnaire_template_id' => $questionnaire->id,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflow->id}/stages/{$stage->id}/questionnaires", [
                'questionnaire_template_id' => $questionnaire->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.questionnaire_template_id.0', 'This questionnaire is already assigned to this stage.');
    }

    public function test_stage_assignment_requires_workflow_to_belong_to_store(): void
    {
        [$franchise, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);

        $storeB    = Store::factory()->for($franchise)->create();
        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB    = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/workflows/{$workflowB->id}/stages/{$stageB->id}/questionnaires", [
                'questionnaire_template_id' => $questionnaire->id,
            ])
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Applicant answers — submission
    // -----------------------------------------------------------------------

    public function test_applicant_can_submit_answers(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $q1 = $this->makeQuestion($questionnaire, ['question_key' => 'work_auth', 'type' => 'boolean', 'position' => 1]);
        $q2 = $this->makeQuestion($questionnaire, ['question_key' => 'availability', 'type' => 'multiselect', 'position' => 2]);

        [, , , $application] = $this->makeApplication($store);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q1->id, 'answer' => true],
                    ['questionnaire_question_id' => $q2->id, 'answer' => ['morning', 'evening']],
                ],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('applicant_answers', [
            'application_id'           => $application->id,
            'questionnaire_question_id' => $q1->id,
        ]);

        $this->assertDatabaseHas('applicant_answers', [
            'application_id'           => $application->id,
            'questionnaire_question_id' => $q2->id,
        ]);
    }

    public function test_answer_values_are_stored_correctly(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $q = $this->makeQuestion($questionnaire, ['question_key' => 'years_exp', 'type' => 'number', 'position' => 1]);

        [, , , $application] = $this->makeApplication($store);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q->id, 'answer' => 5],
                ],
            ])
            ->assertCreated();

        $saved = ApplicantAnswer::where('questionnaire_question_id', $q->id)->first();
        $this->assertEquals(5, $saved->answer);
    }

    public function test_answer_submitted_creates_workflow_activity(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $q = $this->makeQuestion($questionnaire, ['question_key' => 'q1', 'position' => 1]);

        [, , , $application] = $this->makeApplication($store);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q->id, 'answer' => 'Yes'],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $application->id,
            'event_type'     => 'answers_submitted',
        ]);
    }

    public function test_answer_submitted_creates_outbox_event(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $q = $this->makeQuestion($questionnaire, ['question_key' => 'q1', 'position' => 1]);

        [, , , $application] = $this->makeApplication($store);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q->id, 'answer' => 'Yes'],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.questionnaire.submitted',
            'status'     => 'pending',
        ]);
    }

    public function test_required_questions_must_be_answered(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $required = $this->makeQuestion($questionnaire, ['question_key' => 'must_answer', 'is_required' => true, 'position' => 1]);
        $optional = $this->makeQuestion($questionnaire, ['question_key' => 'optional_q', 'is_required' => false, 'position' => 2]);

        [, , , $application] = $this->makeApplication($store);

        // Submit only the optional question — required one missing
        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $optional->id, 'answer' => 'some value'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['answers']]);
    }

    public function test_cannot_answer_question_from_another_questionnaire(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaireA = $this->makeQuestionnaire($store);
        $questionnaireB = $this->makeQuestionnaire($store);
        $qA = $this->makeQuestion($questionnaireA, ['question_key' => 'qA', 'position' => 1]);
        $qB = $this->makeQuestion($questionnaireB, ['question_key' => 'qB', 'position' => 1]);

        [, , , $application] = $this->makeApplication($store);

        // Submit to questionnaire A but include a question from questionnaire B
        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaireA->id,
                'answers' => [
                    ['questionnaire_question_id' => $qA->id, 'answer' => 'valid'],
                    ['questionnaire_question_id' => $qB->id, 'answer' => 'invalid'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['answers']]);
    }

    public function test_duplicate_answer_updates_existing(): void
    {
        [, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $q = $this->makeQuestion($questionnaire, ['question_key' => 'q1', 'position' => 1]);

        [, , , $application] = $this->makeApplication($store);

        // First submission
        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q->id, 'answer' => 'original'],
                ],
            ])
            ->assertCreated();

        // Second submission — same question, different answer
        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q->id, 'answer' => 'updated'],
                ],
            ])
            ->assertCreated();

        // Only one record should exist
        $this->assertDatabaseCount('applicant_answers', 1);

        $saved = ApplicantAnswer::where('application_id', $application->id)
            ->where('questionnaire_question_id', $q->id)
            ->first();

        $this->assertEquals('updated', $saved->answer);
    }

    public function test_answers_endpoint_verifies_application_belongs_to_route_store(): void
    {
        [$franchise, $store, $admin] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $q = $this->makeQuestion($questionnaire, ['question_key' => 'q1', 'position' => 1]);

        $storeB    = Store::factory()->for($franchise)->create();
        $workflowB = HiringWorkflow::factory()->forStore($storeB)->create();
        $stageB    = WorkflowStage::factory()->forWorkflow($workflowB)->initial()->create();
        $jobB      = JobOpening::factory()->withWorkflow($workflowB)->published()->create();
        $appB      = Application::factory()->atStage($stageB)->create(['job_opening_id' => $jobB->id]);

        // Try to submit answers for appB via storeA
        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$appB->id}/answers", [
                'questionnaire_template_id' => $questionnaire->id,
                'answers' => [
                    ['questionnaire_question_id' => $q->id, 'answer' => 'test'],
                ],
            ])
            ->assertNotFound();
    }

    public function test_answers_endpoint_requires_questionnaire_to_belong_to_store(): void
    {
        [$franchise, $store, $admin] = $this->makeStore();
        $storeB = Store::factory()->for($franchise)->create();
        $questionnaireB = $this->makeQuestionnaire($storeB);
        $qB = $this->makeQuestion($questionnaireB, ['question_key' => 'q1', 'position' => 1]);

        [, , , $application] = $this->makeApplication($store);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/applications/{$application->id}/answers", [
                'questionnaire_template_id' => $questionnaireB->id,
                'answers' => [
                    ['questionnaire_question_id' => $qB->id, 'answer' => 'test'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.questionnaire_template_id.0', 'The questionnaire template does not belong to this store.');
    }

    // -----------------------------------------------------------------------
    // All questionnaire routes are store-scoped
    // -----------------------------------------------------------------------

    public function test_all_questionnaire_routes_are_store_scoped(): void
    {
        [$franchise, $store] = $this->makeStore();
        $questionnaire = $this->makeQuestionnaire($store);
        $question      = $this->makeQuestion($questionnaire);
        $manager = User::factory()->storeManager()->create(['franchise_account_id' => $franchise->id]);
        // No UserStoreAccess — all requests should be blocked by store.access middleware

        $id = $questionnaire->id;
        $qid = $question->id;

        $this->actingAs($manager)->getJson("/api/v1/stores/{$store->id}/questionnaires")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/stores/{$store->id}/questionnaires", [])->assertForbidden();
        $this->actingAs($manager)->getJson("/api/v1/stores/{$store->id}/questionnaires/{$id}")->assertForbidden();
        $this->actingAs($manager)->patchJson("/api/v1/stores/{$store->id}/questionnaires/{$id}", [])->assertForbidden();
        $this->actingAs($manager)->deleteJson("/api/v1/stores/{$store->id}/questionnaires/{$id}")->assertForbidden();
        $this->actingAs($manager)->getJson("/api/v1/stores/{$store->id}/questionnaires/{$id}/questions")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/stores/{$store->id}/questionnaires/{$id}/questions", [])->assertForbidden();
        $this->actingAs($manager)->patchJson("/api/v1/stores/{$store->id}/questionnaires/{$id}/questions/{$qid}", [])->assertForbidden();
        $this->actingAs($manager)->deleteJson("/api/v1/stores/{$store->id}/questionnaires/{$id}/questions/{$qid}")->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // No policies
    // -----------------------------------------------------------------------

    public function test_no_policy_classes_exist_for_questionnaires(): void
    {
        $this->assertFileDoesNotExist(app_path('Policies/QuestionnaireTemplatePolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/QuestionnaireQuestionPolicy.php'));
        $this->assertFileDoesNotExist(app_path('Policies/ApplicantAnswerPolicy.php'));
    }
}
