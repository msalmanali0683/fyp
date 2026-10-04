<?php

namespace Tests\Feature;

use App\Models\EvaluationQuestion;
use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EvaluatorReviewManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function createEvaluatorReviewProject(): array
    {
        $question = EvaluationQuestion::create([
            'phase' => 'proposal',
            'text' => 'Overall Quality',
            'max_marks' => 100,
            'order' => 1,
            'is_active' => true,
        ]);

        $leader = User::factory()->create(['status' => 'active', 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $evaluator1 = User::factory()->create(['status' => 'active']);
        $evaluator1->assignRole(['faculty', 'evaluator']);

        $evaluator2 = User::factory()->create(['status' => 'active']);
        $evaluator2->assignRole(['faculty', 'evaluator']);

        $head = User::factory()->create(['status' => 'active']);
        $head->assignRole('fyp-committee-head');

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => null,
            'supervisor_status' => 'pending',
            'title' => 'Evaluator Review Management Test',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'evaluator_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create([
                'project_id' => $project->id,
                'phase' => $phase,
                'content' => $phase === 'proposal' ? 'proposal.pdf' : null,
                'status' => 'draft',
            ]);
        }

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $leader->id,
            'role' => 'leader',
        ]);

        foreach ([$evaluator1, $evaluator2] as $evaluator) {
            ProjectEvaluator::create([
                'project_id' => $project->id,
                'evaluator_id' => $evaluator->id,
            ]);
        }

        $review = EvaluatorReview::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator1->id,
            'fyp_phase' => 'proposal',
            'decision' => 'accepted',
            'marks' => 88,
            'max_marks' => 100,
            'comments' => 'Looks good.',
            'reviewed_at' => now(),
        ]);
        $review->answers()->create(['question_id' => $question->id, 'marks_awarded' => 88]);

        return compact('leader', 'evaluator1', 'evaluator2', 'head', 'project', 'question');
    }

    public function test_committee_head_can_submit_review_on_behalf_of_pending_evaluator(): void
    {
        ['evaluator2' => $evaluator2, 'head' => $head, 'project' => $project, 'question' => $question] = $this->createEvaluatorReviewProject();

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'evaluator_id' => $evaluator2->id,
            'decision' => 'revision_required',
            'comments' => 'Please improve the literature review.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 62],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'committee_final');

        $this->assertDatabaseHas('evaluator_reviews', [
            'project_id' => $project->id,
            'evaluator_id' => $evaluator2->id,
            'decision' => 'revision_required',
            'marks' => 62,
        ]);
    }

    public function test_user_with_permission_can_submit_review_on_behalf_of_evaluator(): void
    {
        ['evaluator2' => $evaluator2, 'project' => $project, 'question' => $question] = $this->createEvaluatorReviewProject();

        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole('fyp-committee-member');
        Permission::firstOrCreate(['name' => 'manage evaluator reviews']);
        Role::firstOrCreate(['name' => 'fyp-committee-member'])->givePermissionTo('manage evaluator reviews');
        $staff->givePermissionTo('manage evaluator reviews');

        Sanctum::actingAs($staff);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'evaluator_id' => $evaluator2->id,
            'decision' => 'accepted',
            'comments' => 'Approved on behalf.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 91],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('evaluator_reviews', [
            'project_id' => $project->id,
            'evaluator_id' => $evaluator2->id,
            'decision' => 'accepted',
            'marks' => 91,
        ]);
    }

    public function test_assigned_evaluator_still_can_submit_their_own_review(): void
    {
        ['evaluator2' => $evaluator2, 'project' => $project, 'question' => $question] = $this->createEvaluatorReviewProject();

        Sanctum::actingAs($evaluator2);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 84],
            ],
        ])->assertOk()->assertJsonPath('data.workflow_stage', 'committee_final');
    }

    public function test_answers_are_required_for_evaluator_review(): void
    {
        ['evaluator2' => $evaluator2, 'project' => $project] = $this->createEvaluatorReviewProject();

        Sanctum::actingAs($evaluator2);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
        ])->assertStatus(422)->assertJsonValidationErrors(['answers']);
    }

    public function test_final_comment_is_required_for_evaluator_review(): void
    {
        ['evaluator2' => $evaluator2, 'project' => $project, 'question' => $question] = $this->createEvaluatorReviewProject();

        Sanctum::actingAs($evaluator2);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 84],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['comments']);
    }

    public function test_marks_above_question_max_are_rejected(): void
    {
        ['evaluator2' => $evaluator2, 'project' => $project, 'question' => $question] = $this->createEvaluatorReviewProject();

        Sanctum::actingAs($evaluator2);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 150],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['answers']);
    }

    public function test_evaluator_updating_marks_clears_other_evaluator_marks(): void
    {
        ['evaluator1' => $evaluator1, 'project' => $project, 'question' => $question] = $this->createEvaluatorReviewProject();

        Sanctum::actingAs($evaluator1);

        $this->postJson("/api/projects/{$project->id}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Updated marks.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 75],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('evaluator_reviews', [
            'project_id' => $project->id,
            'evaluator_id' => $evaluator1->id,
            'marks' => 88,
        ]);
        $this->assertDatabaseHas('evaluator_reviews', [
            'project_id' => $project->id,
            'evaluator_id' => $evaluator1->id,
            'marks' => 75,
        ]);
        $this->assertSame(1, EvaluatorReview::where('project_id', $project->id)->where('fyp_phase', 'proposal')->count());
    }
}
