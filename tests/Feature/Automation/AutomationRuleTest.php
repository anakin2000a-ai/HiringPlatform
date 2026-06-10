<?php

namespace Tests\Feature\Automation;

use App\Models\Application;
use App\Models\AutomationRule;
use App\Models\FranchiseAccount;
use App\Models\HiringWorkflow;
use App\Models\JobOpening;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkflowActivity;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationRuleTest extends TestCase
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

    private function makeWorkflow(Store $store): HiringWorkflow
    {
        return HiringWorkflow::factory()->forStore($store)->create();
    }

    private function makeInitialStage(HiringWorkflow $workflow, array $overrides = []): WorkflowStage
    {
        return WorkflowStage::factory()->forWorkflow($workflow)->initial()->create($overrides);
    }

    private function makeStage(HiringWorkflow $workflow, array $overrides = []): WorkflowStage
    {
        return WorkflowStage::factory()->forWorkflow($workflow)->create($overrides);
    }

    private function makeApplication(Store $store, WorkflowStage $stage): Application
    {
        $job = JobOpening::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $stage->hiring_workflow_id,
            'status'             => 'published',
        ]);

        return Application::factory()->atStage($stage)->create(['job_opening_id' => $job->id]);
    }

    private function makeRule(Store $store, array $overrides = []): AutomationRule
    {
        return AutomationRule::factory()->forStore($store)->create($overrides);
    }

    private function actingAsManager(Store $store): User
    {
        $manager = User::factory()->create(['role' => 'store_manager']);
        \App\Models\UserStoreAccess::create([
            'user_id'  => $manager->id,
            'store_id' => $store->id,
        ]);

        return $manager;
    }

    // -----------------------------------------------------------------------
    // CRUD — index
    // -----------------------------------------------------------------------

    public function test_can_list_automation_rules_for_store(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->makeRule($store);
        $this->makeRule($store);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/automation-rules")
            ->assertOk();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_index_scoped_to_store(): void
    {
        [$franchise, $storeA, $admin] = $this->makeStore();
        $storeB = Store::factory()->for($franchise)->create();

        $this->makeRule($storeA);
        $this->makeRule($storeB);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/stores/{$storeA->id}/automation-rules")
            ->assertOk();

        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_requires_auth(): void
    {
        [, $store] = $this->makeStore();

        $this->getJson("/api/v1/stores/{$store->id}/automation-rules")
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // CRUD — store (create)
    // -----------------------------------------------------------------------

    public function test_franchise_admin_can_create_automation_rule(): void
    {
        [, $store, $admin] = $this->makeStore();

        $payload = [
            'name'    => 'Auto-fire on creation',
            'trigger' => 'application_created',
            'actions' => [['type' => 'create_activity', 'event_type' => 'test_event']],
        ];

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Auto-fire on creation')
            ->assertJsonPath('data.trigger', 'application_created');

        $this->assertDatabaseHas('automation_rules', [
            'store_id' => $store->id,
            'name'     => 'Auto-fire on creation',
            'trigger'  => 'application_created',
        ]);
    }

    public function test_store_manager_can_create_automation_rule(): void
    {
        [, $store] = $this->makeStore();
        $manager = $this->actingAsManager($store);

        $this->actingAs($manager)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", [
                'name'    => 'Manager rule',
                'trigger' => 'stage_entered',
                'actions' => [['type' => 'create_activity', 'event_type' => 'stage_activity']],
            ])
            ->assertCreated();
    }

    public function test_non_manager_cannot_create_automation_rule(): void
    {
        [, $store] = $this->makeStore();
        $viewer = User::factory()->create(['role' => 'viewer']);
        \App\Models\UserStoreAccess::create(['user_id' => $viewer->id, 'store_id' => $store->id]);

        $this->actingAs($viewer)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", [
                'name'    => 'Viewer rule',
                'trigger' => 'application_created',
                'actions' => [['type' => 'create_activity', 'event_type' => 'x']],
            ])
            ->assertForbidden();
    }

    public function test_create_rejects_invalid_trigger(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", [
                'name'    => 'Bad trigger',
                'trigger' => 'not_a_valid_trigger',
                'actions' => [['type' => 'create_activity', 'event_type' => 'x']],
            ])
            ->assertUnprocessable();
    }

    public function test_create_rejects_invalid_action_type(): void
    {
        [, $store, $admin] = $this->makeStore();

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", [
                'name'    => 'Bad action',
                'trigger' => 'application_created',
                'actions' => [['type' => 'explode_database']],
            ])
            ->assertUnprocessable();
    }

    public function test_create_rejects_workflow_from_other_store(): void
    {
        [$franchise, $store, $admin] = $this->makeStore();
        $otherStore = Store::factory()->for($franchise)->create();
        $otherWorkflow = $this->makeWorkflow($otherStore);

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", [
                'name'               => 'Cross-store rule',
                'trigger'            => 'application_created',
                'hiring_workflow_id' => $otherWorkflow->id,
                'actions'            => [['type' => 'create_activity', 'event_type' => 'x']],
            ])
            ->assertStatus(422);
    }

    public function test_create_with_conditions(): void
    {
        [, $store, $admin] = $this->makeStore();

        $conditions = [
            'group' => 'all',
            'rules' => [
                ['field' => 'application.score', 'operator' => 'greater_than', 'value' => 80],
            ],
        ];

        $this->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/automation-rules", [
                'name'       => 'Score rule',
                'trigger'    => 'stage_entered',
                'conditions' => $conditions,
                'actions'    => [['type' => 'create_activity', 'event_type' => 'high_score']],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('automation_rules', [
            'store_id' => $store->id,
            'name'     => 'Score rule',
        ]);
    }

    // -----------------------------------------------------------------------
    // CRUD — show
    // -----------------------------------------------------------------------

    public function test_can_show_automation_rule(): void
    {
        [, $store, $admin] = $this->makeStore();
        $rule = $this->makeRule($store, ['name' => 'Specific Rule']);

        $this->actingAs($admin)
            ->getJson("/api/v1/automation-rules/{$rule->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Specific Rule');
    }

    public function test_cannot_show_rule_from_other_store(): void
    {
        [, $storeA, $adminA] = $this->makeStore();
        [, $storeB]          = $this->makeStore();
        $rule = $this->makeRule($storeB);

        $this->actingAs($adminA)
            ->getJson("/api/v1/automation-rules/{$rule->id}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // CRUD — update
    // -----------------------------------------------------------------------

    public function test_can_update_automation_rule(): void
    {
        [, $store, $admin] = $this->makeStore();
        $rule = $this->makeRule($store);

        $this->actingAs($admin)
            ->patchJson("/api/v1/automation-rules/{$rule->id}", [
                'name'      => 'Updated Name',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_update_forbidden_for_non_manager(): void
    {
        [, $store] = $this->makeStore();
        $rule    = $this->makeRule($store);
        $viewer  = User::factory()->create(['role' => 'viewer']);
        \App\Models\UserStoreAccess::create(['user_id' => $viewer->id, 'store_id' => $store->id]);

        $this->actingAs($viewer)
            ->patchJson("/api/v1/automation-rules/{$rule->id}", ['name' => 'Hack'])
            ->assertForbidden();
    }

    public function test_cannot_update_rule_from_other_store(): void
    {
        [, $storeA, $adminA] = $this->makeStore();
        [, $storeB]          = $this->makeStore();
        $rule = $this->makeRule($storeB);

        $this->actingAs($adminA)
            ->patchJson("/api/v1/automation-rules/{$rule->id}", ['name' => 'Hack'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // CRUD — destroy
    // -----------------------------------------------------------------------

    public function test_can_delete_automation_rule(): void
    {
        [, $store, $admin] = $this->makeStore();
        $rule = $this->makeRule($store);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/automation-rules/{$rule->id}")
            ->assertOk();

        $this->assertDatabaseMissing('automation_rules', ['id' => $rule->id]);
    }

    public function test_delete_forbidden_for_non_manager(): void
    {
        [, $store] = $this->makeStore();
        $rule    = $this->makeRule($store);
        $viewer  = User::factory()->create(['role' => 'viewer']);
        \App\Models\UserStoreAccess::create(['user_id' => $viewer->id, 'store_id' => $store->id]);

        $this->actingAs($viewer)
            ->deleteJson("/api/v1/automation-rules/{$rule->id}")
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // Engine — condition evaluation
    // -----------------------------------------------------------------------

    public function test_rule_fires_when_no_conditions(): void
    {
        [, $store, $admin] = $this->makeStore();
        $workflow = $this->makeWorkflow($store);
        $stage    = $this->makeInitialStage($workflow);
        $app      = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger'    => 'application_created',
            'conditions' => null,
            'actions'    => [['type' => 'create_activity', 'event_type' => 'no_conditions_fired']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'no_conditions_fired',
        ]);
    }

    public function test_rule_skipped_when_conditions_not_met(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger'    => 'application_created',
            'conditions' => [
                'group' => 'all',
                'rules' => [
                    ['field' => 'application.score', 'operator' => 'greater_than', 'value' => 9999],
                ],
            ],
            'actions' => [['type' => 'create_activity', 'event_type' => 'should_not_fire']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseMissing('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'should_not_fire',
        ]);
    }

    public function test_rule_skipped_for_wrong_trigger(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger' => 'stage_entered',
            'actions' => [['type' => 'create_activity', 'event_type' => 'wrong_trigger_fired']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseMissing('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'wrong_trigger_fired',
        ]);
    }

    public function test_inactive_rule_not_fired(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger'   => 'application_created',
            'is_active' => false,
            'actions'   => [['type' => 'create_activity', 'event_type' => 'inactive_rule_fired']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseMissing('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'inactive_rule_fired',
        ]);
    }

    public function test_rules_execute_in_priority_order(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger'  => 'application_created',
            'priority' => 200,
            'actions'  => [['type' => 'create_activity', 'event_type' => 'second']],
        ]);

        $this->makeRule($store, [
            'trigger'  => 'application_created',
            'priority' => 50,
            'actions'  => [['type' => 'create_activity', 'event_type' => 'first']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $activities = WorkflowActivity::where('application_id', $app->id)
            ->whereIn('event_type', ['first', 'second'])
            ->orderBy('id')
            ->pluck('event_type')
            ->toArray();

        $this->assertEquals(['first', 'second'], $activities);
    }

    public function test_any_group_condition(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger'    => 'application_created',
            'conditions' => [
                'group' => 'any',
                'rules' => [
                    ['field' => 'application.score', 'operator' => 'greater_than', 'value' => 9999],
                    ['field' => 'application.status', 'operator' => 'equals', 'value' => 'active'],
                ],
            ],
            'actions' => [['type' => 'create_activity', 'event_type' => 'any_group_fired']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'any_group_fired',
        ]);
    }

    // -----------------------------------------------------------------------
    // Engine — action: create_activity
    // -----------------------------------------------------------------------

    public function test_create_activity_action_records_activity_with_automation_actor(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'create_activity', 'event_type' => 'automation_fired']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'automation_fired',
            'actor_type'     => 'automation',
        ]);
    }

    // -----------------------------------------------------------------------
    // Engine — action: publish_event
    // -----------------------------------------------------------------------

    public function test_publish_event_action_creates_outbox_record(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'publish_event', 'event_type' => 'hiring.test.event']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'hiring.test.event',
            'status'     => 'pending',
        ]);
    }

    // -----------------------------------------------------------------------
    // Engine — action: set_score / increment_score
    // -----------------------------------------------------------------------

    public function test_set_score_action_updates_application_score(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'set_score', 'score' => 75]],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'score' => 75]);
        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'score_set',
            'actor_type'     => 'automation',
        ]);
    }

    public function test_increment_score_action_adds_to_existing_score(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);
        $app->update(['score' => 50]);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'increment_score', 'by' => 10]],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('applications', ['id' => $app->id, 'score' => 60]);
    }

    // -----------------------------------------------------------------------
    // Engine — action: move_to_stage
    // -----------------------------------------------------------------------

    public function test_move_to_stage_action_moves_application(): void
    {
        // stage_slug in the action payload maps to workflow_stages.name (no slug column in current schema).
        // The stage has name = "Interview"; the action payload uses "stage_slug": "Interview".
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $initial   = $this->makeInitialStage($workflow, ['name' => 'Applied']);
        $next      = $this->makeStage($workflow, ['name' => 'Interview', 'stage_type' => 'interview']);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'   => $workflow->id,
            'from_stage_id'        => $initial->id,
            'to_stage_id'          => $next->id,
            'is_manual_allowed'    => false,
            'is_automatic_allowed' => true,
        ]);

        $app = $this->makeApplication($store, $initial);

        // Action uses "stage_slug": "Interview" — matched against workflow_stages.name
        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'move_to_stage', 'stage_slug' => 'Interview']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        // Move goes through ApplicationStageService (transition_type = 'automatic')
        $this->assertDatabaseHas('applications', [
            'id'               => $app->id,
            'current_stage_id' => $next->id,
        ]);

        $this->assertDatabaseHas('application_stage_transitions', [
            'application_id'  => $app->id,
            'to_stage_id'     => $next->id,
            'transition_type' => 'automatic',
        ]);
    }

    public function test_current_stage_slug_condition_maps_to_stage_name(): void
    {
        // current_stage.slug in condition field paths maps to workflow_stages.name in current schema.
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow, ['name' => 'Applied']);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger'    => 'application_created',
            'conditions' => [
                'group' => 'all',
                'rules' => [
                    // 'current_stage.slug' resolves to WorkflowStage.name = 'Applied'
                    ['field' => 'current_stage.slug', 'operator' => 'equals', 'value' => 'Applied'],
                ],
            ],
            'actions' => [['type' => 'create_activity', 'event_type' => 'slug_maps_to_name']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'slug_maps_to_name',
        ]);
    }

    public function test_move_to_stage_logs_failure_when_stage_not_found(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'move_to_stage', 'stage_slug' => 'NonExistentStage']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'automation_action_failed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Engine — action: reject_application / mark_hired
    // -----------------------------------------------------------------------

    public function test_reject_application_action_moves_to_rejected_stage(): void
    {
        Queue::fake();
        [, $store] = $this->makeStore();
        $workflow   = $this->makeWorkflow($store);
        $initial    = $this->makeInitialStage($workflow, ['name' => 'Applied']);
        $rejected   = $this->makeStage($workflow, ['name' => 'Rejected', 'stage_type' => 'rejected', 'is_terminal' => true]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'   => $workflow->id,
            'from_stage_id'        => $initial->id,
            'to_stage_id'          => $rejected->id,
            'is_manual_allowed'    => false,
            'is_automatic_allowed' => true,
        ]);

        $app = $this->makeApplication($store, $initial);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'reject_application']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('applications', [
            'id'               => $app->id,
            'current_stage_id' => $rejected->id,
            'status'           => 'rejected',
        ]);
    }

    public function test_mark_hired_action_moves_to_hired_stage(): void
    {
        Queue::fake();
        [, $store] = $this->makeStore();
        $workflow   = $this->makeWorkflow($store);
        $initial    = $this->makeInitialStage($workflow, ['name' => 'Applied']);
        $hired      = $this->makeStage($workflow, ['name' => 'Hired', 'stage_type' => 'hired', 'is_terminal' => true]);

        WorkflowStageTransition::factory()->create([
            'hiring_workflow_id'   => $workflow->id,
            'from_stage_id'        => $initial->id,
            'to_stage_id'          => $hired->id,
            'is_manual_allowed'    => false,
            'is_automatic_allowed' => true,
        ]);

        $app = $this->makeApplication($store, $initial);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'mark_hired']],
        ]);

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app);

        $this->assertDatabaseHas('applications', [
            'id'               => $app->id,
            'current_stage_id' => $hired->id,
            'status'           => 'hired',
        ]);
    }

    // -----------------------------------------------------------------------
    // Loop prevention
    // -----------------------------------------------------------------------

    public function test_same_rule_not_executed_twice_in_cycle(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        $this->makeRule($store, [
            'trigger' => 'application_created',
            'actions' => [['type' => 'create_activity', 'event_type' => 'loop_activity']],
        ]);

        // Pass the same rule id already executed — should not run again
        $rule = AutomationRule::where('store_id', $store->id)->first();

        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app, [$rule->id]);

        $this->assertDatabaseMissing('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'loop_activity',
        ]);
    }

    public function test_max_depth_prevents_infinite_recursion(): void
    {
        [, $store] = $this->makeStore();
        $workflow  = $this->makeWorkflow($store);
        $stage     = $this->makeInitialStage($workflow);
        $app       = $this->makeApplication($store, $stage);

        // Calling at MAX_DEPTH should be a no-op
        app(\App\Services\Automation\AutomationRuleEngine::class)
            ->evaluate('application_created', $app, [], \App\Services\Automation\AutomationRuleEngine::MAX_DEPTH);

        // No activities created because depth guard returns early
        $this->assertDatabaseMissing('workflow_activities', [
            'application_id' => $app->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Integration — trigger on application creation
    // -----------------------------------------------------------------------

    public function test_engine_fires_on_application_created_via_api(): void
    {
        [, $store] = $this->makeStore();
        $workflow = $this->makeWorkflow($store);
        $this->makeInitialStage($workflow);
        $job = JobOpening::factory()->create([
            'store_id'           => $store->id,
            'hiring_workflow_id' => $workflow->id,
            'status'             => 'published',
        ]);

        AutomationRule::factory()->forStore($store)->create([
            'trigger' => 'application_created',
            'actions' => [['type' => 'create_activity', 'event_type' => 'fired_on_create']],
        ]);

        $this->postJson("/api/v1/stores/{$store->id}/job-openings/{$job->id}/apply", [
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
        ])->assertCreated();

        $app = Application::whereHas('jobOpening', fn ($q) => $q->where('id', $job->id))->first();

        $this->assertDatabaseHas('workflow_activities', [
            'application_id' => $app->id,
            'event_type'     => 'fired_on_create',
        ]);
    }

    // -----------------------------------------------------------------------
    // No policy assertion
    // -----------------------------------------------------------------------

    public function test_no_policy_classes_used(): void
    {
        $policyPath = app_path('Policies');

        if (! is_dir($policyPath)) {
            $this->assertTrue(true);

            return;
        }

        $files = glob($policyPath . '/*Policy.php');
        $this->assertEmpty($files, 'No Policy classes should exist.');
    }
}
