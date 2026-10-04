<?php

namespace Tests\Feature;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\FypSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluatorMarksVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function createReviewedProject(): array
    {
        $leader = User::factory()->create(['status' => 'active', 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $teammate = User::factory()->create(['status' => 'active', 'is_proposal_enrolled' => true]);
        $teammate->assignRole('student');

        $supervisor = User::factory()->create(['status' => 'active']);
        $supervisor->assignRole(['faculty', 'supervisor']);

        $otherSupervisor = User::factory()->create(['status' => 'active']);
        $otherSupervisor->assignRole(['faculty', 'supervisor']);

        $evaluator = User::factory()->create(['status' => 'active']);
        $evaluator->assignRole(['faculty', 'evaluator']);

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'accepted',
            'title' => 'Marks Visibility Test Project',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'evaluator_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $teammate->id,
            'role' => 'member',
        ]);

        ProjectEvaluator::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
        ]);

        EvaluatorReview::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'fyp_phase' => 'proposal',
            'decision' => 'accepted',
            'marks' => 91,
            'comments' => 'Strong proposal, minor scope clarification needed.',
            'reviewed_at' => now(),
        ]);

        return compact('leader', 'teammate', 'supervisor', 'otherSupervisor', 'evaluator', 'project');
    }

    public function test_project_leader_sees_marks_and_comments_but_not_evaluator_identity(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createReviewedProject();

        Sanctum::actingAs($leader);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.can_view_evaluator_marks', true);
        $response->assertJsonPath('data.evaluator_reviews.0.marks', 91);
        $response->assertJsonPath('data.evaluator_reviews.0.comments', 'Strong proposal, minor scope clarification needed.');
        $response->assertJsonPath('data.evaluator_reviews.0.evaluator', null);
        $this->assertSame([], $response->json('data.evaluators'));
    }

    public function test_team_member_also_sees_marks_without_evaluator_identity(): void
    {
        ['teammate' => $teammate, 'project' => $project] = $this->createReviewedProject();

        Sanctum::actingAs($teammate);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.can_view_evaluator_marks', true);
        $response->assertJsonPath('data.evaluator_reviews.0.marks', 91);
        $response->assertJsonPath('data.evaluator_reviews.0.evaluator', null);
    }

    public function test_assigned_supervisor_sees_marks_comments_and_evaluator_identity(): void
    {
        ['supervisor' => $supervisor, 'evaluator' => $evaluator, 'project' => $project] = $this->createReviewedProject();

        Sanctum::actingAs($supervisor);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.can_view_evaluator_marks', true);
        $response->assertJsonPath('data.evaluator_reviews.0.marks', 91);
        $response->assertJsonPath('data.evaluator_reviews.0.evaluator.id', $evaluator->id);
        $this->assertNotEmpty($response->json('data.evaluators'));
    }

    public function test_unrelated_supervisor_cannot_see_marks(): void
    {
        ['otherSupervisor' => $otherSupervisor, 'project' => $project] = $this->createReviewedProject();

        Sanctum::actingAs($otherSupervisor);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        // Faculty can browse project details generically, but marks/comments stay
        // restricted to the assigned supervisor, the team, and admin/committee.
        $response->assertJsonPath('data.can_view_evaluator_marks', false);
        $this->assertSame([], $response->json('data.evaluator_reviews'));
        $this->assertSame([], $response->json('data.evaluators'));
    }

    public function test_admin_can_disable_student_mark_visibility_and_it_takes_effect_immediately(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createReviewedProject();

        app(FypSettingsService::class)->updateEvaluatorVisibility([
            'proposal' => [
                'supervisor_view_identity_during_review' => true,
                'supervisor_view_identity_after_decision' => true,
                'supervisor_view_marks_during_review' => true,
                'supervisor_view_marks_after_decision' => true,
                'student_view_identity_during_review' => false,
                'student_view_identity_after_decision' => false,
                'student_view_marks_during_review' => false,
                'student_view_marks_after_decision' => false,
            ],
        ]);

        Sanctum::actingAs($leader);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.can_view_evaluator_marks', false);
        $this->assertSame([], $response->json('data.evaluator_reviews'));
    }

    public function test_visibility_setting_is_scoped_to_its_own_phase(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createReviewedProject();

        // Turn off student mark visibility for Phase 1 only — the Proposal phase
        // (where this test project actually lives) must keep the default (visible).
        app(FypSettingsService::class)->updateEvaluatorVisibility([
            'phase_1' => [
                'supervisor_view_identity_during_review' => true,
                'supervisor_view_identity_after_decision' => true,
                'supervisor_view_marks_during_review' => true,
                'supervisor_view_marks_after_decision' => true,
                'student_view_identity_during_review' => false,
                'student_view_identity_after_decision' => false,
                'student_view_marks_during_review' => false,
                'student_view_marks_after_decision' => false,
            ],
        ]);

        Sanctum::actingAs($leader);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.can_view_evaluator_marks', true);
        $response->assertJsonPath('data.evaluator_reviews.0.marks', 91);
    }

    public function test_admin_can_grant_student_evaluator_identity_visibility(): void
    {
        ['leader' => $leader, 'evaluator' => $evaluator, 'project' => $project] = $this->createReviewedProject();

        app(FypSettingsService::class)->updateEvaluatorVisibility([
            'proposal' => [
                'supervisor_view_identity_during_review' => true,
                'supervisor_view_identity_after_decision' => true,
                'supervisor_view_marks_during_review' => true,
                'supervisor_view_marks_after_decision' => true,
                'student_view_identity_during_review' => true,
                'student_view_identity_after_decision' => true,
                'student_view_marks_during_review' => true,
                'student_view_marks_after_decision' => true,
            ],
        ]);

        Sanctum::actingAs($leader);
        $response = $this->getJson("/api/projects/{$project->id}")->assertOk();

        $response->assertJsonPath('data.evaluator_reviews.0.evaluator.id', $evaluator->id);
        $this->assertNotEmpty($response->json('data.evaluators'));
    }

    public function test_visibility_can_differ_before_and_after_decision(): void
    {
        ['supervisor' => $supervisor, 'project' => $project] = $this->createReviewedProject();

        // Supervisor should only see marks once the phase decision is finalized, not while
        // evaluation is still in progress.
        app(FypSettingsService::class)->updateEvaluatorVisibility([
            'proposal' => [
                'supervisor_view_identity_during_review' => false,
                'supervisor_view_identity_after_decision' => true,
                'supervisor_view_marks_during_review' => false,
                'supervisor_view_marks_after_decision' => true,
                'student_view_identity_during_review' => false,
                'student_view_identity_after_decision' => false,
                'student_view_marks_during_review' => false,
                'student_view_marks_after_decision' => false,
            ],
        ]);

        Sanctum::actingAs($supervisor);

        // Still under evaluation (workflow_stage = 'evaluator_review'): hidden.
        $duringReview = $this->getJson("/api/projects/{$project->id}")->assertOk();
        $duringReview->assertJsonPath('data.can_view_evaluator_marks', false);

        // Finalize the proposal decision: now visible.
        $project->update(['workflow_stage' => 'approved']);
        $afterDecision = $this->getJson("/api/projects/{$project->id}")->assertOk();
        $afterDecision->assertJsonPath('data.can_view_evaluator_marks', true);
        $afterDecision->assertJsonPath('data.evaluator_reviews.0.marks', 91);
    }

    public function test_updating_evaluator_visibility_requires_permission(): void
    {
        $faculty = User::factory()->create(['status' => 'active']);
        $faculty->assignRole('faculty');

        Sanctum::actingAs($faculty);

        $this->putJson('/api/proposals/settings', $this->validSettingsPayload([
            'proposal' => ['student_view_marks_after_decision' => false],
        ]))->assertStatus(403);
    }

    public function test_admin_can_update_evaluator_visibility_via_settings_endpoint(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/proposals/settings', $this->validSettingsPayload([
            'phase_2' => [
                'student_view_marks_after_decision' => false,
                'student_view_marks_during_review' => false,
            ],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.evaluator_visibility.phase_2.student_view_marks_after_decision', false);
        // Untouched phases keep their own (default) values.
        $response->assertJsonPath('data.evaluator_visibility.proposal.student_view_marks_after_decision', true);

        $show = $this->getJson('/api/proposals/settings')->assertOk();
        $show->assertJsonPath('data.evaluator_visibility.phase_2.student_view_marks_after_decision', false);
        $show->assertJsonPath('data.evaluator_visibility.proposal.student_view_marks_after_decision', true);
    }

    protected function validSettingsPayload(array $evaluatorVisibilityPhaseOverrides = []): array
    {
        $settingsService = app(FypSettingsService::class);
        $evaluatorVisibility = $settingsService->defaultEvaluatorVisibility();

        foreach ($evaluatorVisibilityPhaseOverrides as $phase => $overrides) {
            $evaluatorVisibility[$phase] = array_merge($evaluatorVisibility[$phase], $overrides);
        }

        return [
            'min_members' => 3,
            'max_members' => 5,
            'min_evaluators' => 2,
            'max_evaluators' => 3,
            'supervisor_limits' => [
                'proposal' => 5,
                'phase_1' => 5,
                'phase_2' => 5,
            ],
            'evaluator_limits' => [
                'proposal' => 5,
                'phase_1' => 5,
                'phase_2' => 5,
            ],
            'evaluator_visibility' => $evaluatorVisibility,
        ];
    }
}
