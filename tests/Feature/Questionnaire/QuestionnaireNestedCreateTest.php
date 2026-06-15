<?php

namespace Tests\Feature\Questionnaire;

use App\Models\FranchiseAccount;
use App\Models\QuestionnaireQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionnaireNestedCreateTest extends TestCase
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

    private function actingAsWithAccess(User $admin, Store $store): static
    {
        UserStoreAccess::firstOrCreate(['user_id' => $admin->id, 'store_id' => $store->id]);

        return $this->actingAs($admin);
    }

    private function url(Store $store): string
    {
        return "/api/v1/stores/{$store->store_name}/questionnaires";
    }

    // -----------------------------------------------------------------------
    // Without questions — existing behaviour preserved
    // -----------------------------------------------------------------------

    public function test_create_questionnaire_without_questions_succeeds(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'    => 'Screening Q',
                'version' => 1,
                'status'  => 'active',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.name', 'Screening Q');
        $response->assertJsonMissing(['data.questions']);

        $this->assertDatabaseHas('questionnaire_templates', ['name' => 'Screening Q', 'store_id' => $store->id]);
        $this->assertSame(0, QuestionnaireQuestion::count());
    }

    // -----------------------------------------------------------------------
    // With questions
    // -----------------------------------------------------------------------

    public function test_create_questionnaire_with_nested_questions_creates_both(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'Barista Screen',
                'status' => 'active',
                'questions' => [
                    [
                        'question_key' => 'experience',
                        'label'        => 'Do you have barista experience?',
                        'type'         => 'boolean',
                        'is_required'  => true,
                        'position'     => 1,
                    ],
                    [
                        'question_key' => 'motivation',
                        'label'        => 'Why do you want to work here?',
                        'type'         => 'text',
                        'is_required'  => false,
                        'position'     => 2,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $questionnaire = QuestionnaireTemplate::where('name', 'Barista Screen')->firstOrFail();
        $this->assertSame($store->id, $questionnaire->store_id);
        $this->assertSame(2, $questionnaire->questions()->count());

        $q1 = $questionnaire->questions()->where('question_key', 'experience')->first();
        $this->assertNotNull($q1);
        $this->assertSame('boolean', $q1->type instanceof \BackedEnum ? $q1->type->value : $q1->type);
        $this->assertTrue((bool) $q1->is_required);

        $q2 = $questionnaire->questions()->where('question_key', 'motivation')->first();
        $this->assertNotNull($q2);
        $this->assertSame('text', $q2->type instanceof \BackedEnum ? $q2->type->value : $q2->type);
    }

    public function test_response_includes_questions_when_provided(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'With Q Response',
                'questions' => [
                    [
                        'question_key' => 'q1',
                        'label'        => 'First question',
                        'type'         => 'text',
                        'position'     => 1,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'data.questions');
        $response->assertJsonPath('data.questions.0.question_key', 'q1');
    }

    // -----------------------------------------------------------------------
    // Validation — nested fields
    // -----------------------------------------------------------------------

    public function test_missing_question_key_fails_validation(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'Bad Q',
                'questions' => [
                    [
                        'label'    => 'Missing key',
                        'type'     => 'text',
                        'position' => 1,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['questions.0.question_key']);
    }

    public function test_invalid_question_type_fails_validation(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'Bad Type',
                'questions' => [
                    [
                        'question_key' => 'q1',
                        'label'        => 'Question',
                        'type'         => 'invalid_type',
                        'position'     => 1,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['questions.0.type']);
    }

    public function test_duplicate_question_keys_fail_validation(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'Dup Keys',
                'questions' => [
                    ['question_key' => 'same', 'label' => 'A', 'type' => 'text', 'position' => 1],
                    ['question_key' => 'same', 'label' => 'B', 'type' => 'text', 'position' => 2],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($v) => str_contains($v, 'unique') || str_contains($v, 'Validation'));
    }

    public function test_duplicate_question_positions_fail_validation(): void
    {
        [, $store, $admin] = $this->makeStore();

        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'Dup Positions',
                'questions' => [
                    ['question_key' => 'q1', 'label' => 'A', 'type' => 'text', 'position' => 1],
                    ['question_key' => 'q2', 'label' => 'B', 'type' => 'text', 'position' => 1],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', fn ($v) => str_contains($v, 'unique') || str_contains($v, 'Validation'));
    }

    // -----------------------------------------------------------------------
    // Rollback on failure
    // -----------------------------------------------------------------------

    public function test_transaction_rolls_back_when_question_data_is_invalid(): void
    {
        [, $store, $admin] = $this->makeStore();

        // missing required 'type' on second question — DB insert would fail
        $response = $this->actingAsWithAccess($admin, $store)
            ->postJson($this->url($store), [
                'name'   => 'Rollback Test',
                'questions' => [
                    ['question_key' => 'q1', 'label' => 'OK', 'type' => 'text', 'position' => 1],
                    ['question_key' => 'q2', 'label' => 'No type', 'position' => 2],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('questionnaire_templates', ['name' => 'Rollback Test']);
        $this->assertSame(0, QuestionnaireQuestion::count());
    }

    // -----------------------------------------------------------------------
    // Unauthorized
    // -----------------------------------------------------------------------

    public function test_unauthenticated_request_rejected(): void
    {
        [, $store] = $this->makeStore();

        $this->postJson($this->url($store), ['name' => 'Q'])
            ->assertStatus(401);
    }

    public function test_user_without_store_access_rejected(): void
    {
        [, $store]           = $this->makeStore();
        $franchise2          = FranchiseAccount::factory()->create();
        $otherStore          = Store::factory()->for($franchise2)->create();
        $outsider            = User::factory()->create();
        UserStoreAccess::create(['user_id' => $outsider->id, 'store_id' => $otherStore->id, 'role' => 'franchise_admin', 'access_scope' => 'franchise', 'status' => 'active']);

        $this->actingAs($outsider)
            ->postJson($this->url($store), ['name' => 'Q'])
            ->assertStatus(403);
    }
}
