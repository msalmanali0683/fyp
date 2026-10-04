<?php

namespace Tests\Feature;

use App\Models\EvaluationQuestion;
use App\Models\EvaluatorReview;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_admin_can_create_a_question(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/question-bank', [
            'phase' => 'proposal',
            'text' => 'Is the problem statement clear?',
            'max_marks' => 10,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.max_marks', 10);

        $this->assertDatabaseHas('evaluation_questions', [
            'phase' => 'proposal',
            'text' => 'Is the problem statement clear?',
            'max_marks' => 10,
        ]);
    }

    public function test_non_privileged_user_cannot_create_a_question(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $this->postJson('/api/question-bank', [
            'phase' => 'phase_1',
            'text' => 'Some question',
            'max_marks' => 5,
        ])->assertStatus(422);
    }

    public function test_any_authenticated_user_can_list_active_questions_for_a_phase(): void
    {
        EvaluationQuestion::create(['phase' => 'phase_1', 'text' => 'Q1', 'max_marks' => 20, 'order' => 1, 'is_active' => true]);
        EvaluationQuestion::create(['phase' => 'phase_1', 'text' => 'Q2 (retired)', 'max_marks' => 10, 'order' => 2, 'is_active' => false]);

        $evaluator = User::factory()->create(['status' => 'active']);
        $evaluator->assignRole('evaluator');

        Sanctum::actingAs($evaluator);
        $response = $this->getJson('/api/question-bank?phase=phase_1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Q1', $response->json('data.0.text'));
    }

    public function test_admin_can_update_and_deactivate_a_question(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        $question = EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Q1', 'max_marks' => 10, 'order' => 1, 'is_active' => true]);

        Sanctum::actingAs($admin);
        $this->putJson("/api/question-bank/{$question->id}", [
            'max_marks' => 15,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.max_marks', 15)->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_delete_a_question_with_no_answers(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        $question = EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Q1', 'max_marks' => 10, 'order' => 1, 'is_active' => true]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/question-bank/{$question->id}")->assertOk();

        $this->assertDatabaseMissing('evaluation_questions', ['id' => $question->id]);
    }

    public function test_cannot_delete_a_question_that_already_has_answers(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        $question = EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Q1', 'max_marks' => 10, 'order' => 1, 'is_active' => true]);

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $leader->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Question Deletion Test',
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'evaluator_review',
            'status' => 'active',
        ]);

        $review = EvaluatorReview::create([
            'project_id' => $project->id,
            'evaluator_id' => $admin->id,
            'fyp_phase' => 'proposal',
            'decision' => 'accepted',
            'marks' => 8,
            'max_marks' => 10,
            'comments' => 'Fine.',
            'reviewed_at' => now(),
        ]);
        $review->answers()->create(['question_id' => $question->id, 'marks_awarded' => 8]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/question-bank/{$question->id}")->assertStatus(422);

        $this->assertDatabaseHas('evaluation_questions', ['id' => $question->id]);
    }

    public function test_evaluator_answers_multi_question_bank_and_total_is_summed(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $q1 = EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Clarity', 'max_marks' => 40, 'order' => 1, 'is_active' => true]);
        $q2 = EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Feasibility', 'max_marks' => 60, 'order' => 2, 'is_active' => true]);

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $evaluator = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $evaluator->assignRole(['faculty', 'evaluator']);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Multi Question Test',
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'evaluator_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create(['project_id' => $project->id, 'phase' => $phase, 'status' => 'draft']);
        }

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);
        ProjectEvaluator::create(['project_id' => $project->id, 'evaluator_id' => $evaluator->id]);

        Sanctum::actingAs($evaluator);

        $response = $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Solid proposal overall.',
            'answers' => [
                ['question_id' => $q1->id, 'marks' => 35, 'comment' => 'Very clear.'],
                ['question_id' => $q2->id, 'marks' => 50],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('evaluator_reviews', [
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'marks' => 85,
            'max_marks' => 100,
        ]);

        $review = EvaluatorReview::where('project_id', $project->id)->firstOrFail();
        $this->assertSame(2, $review->answers()->count());
        $this->assertDatabaseHas('evaluator_review_answers', [
            'evaluator_review_id' => $review->id,
            'question_id' => $q1->id,
            'marks_awarded' => 35,
            'comment' => 'Very clear.',
        ]);
    }

    public function test_missing_answer_for_a_question_is_rejected(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $q1 = EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Clarity', 'max_marks' => 40, 'order' => 1, 'is_active' => true]);
        EvaluationQuestion::create(['phase' => 'proposal', 'text' => 'Feasibility', 'max_marks' => 60, 'order' => 2, 'is_active' => true]);

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $evaluator = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $evaluator->assignRole(['faculty', 'evaluator']);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Missing Answer Test',
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'evaluator_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create(['project_id' => $project->id, 'phase' => $phase, 'status' => 'draft']);
        }

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);
        ProjectEvaluator::create(['project_id' => $project->id, 'evaluator_id' => $evaluator->id]);

        Sanctum::actingAs($evaluator);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Only answered one question.',
            'answers' => [
                ['question_id' => $q1->id, 'marks' => 35],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['answers']);
    }
}
