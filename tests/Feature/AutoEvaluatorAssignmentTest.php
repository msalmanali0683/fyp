<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutoEvaluatorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function makeSession(Program $program): ProposalSession
    {
        return ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2025',
            'code' => 'F25-'.uniqid(),
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);
    }

    protected function makeProject(Program $program, ProposalSession $session, ?User $supervisor = null): Project
    {
        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $leader->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor?->id,
            'supervisor_status' => $supervisor ? 'accepted' : 'pending',
            'title' => 'Project '.uniqid(),
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create(['project_id' => $project->id, 'phase' => $phase, 'status' => 'draft']);
        }

        return $project;
    }

    protected function makeEvaluator(Program $program, array $limits = []): User
    {
        $evaluator = User::factory()->create(array_merge(['status' => 'active', 'program_id' => $program->id], $limits));
        $evaluator->assignRole(['faculty', 'evaluator']);
        app(ProgramScopeService::class)->syncMembership($evaluator, $program->id, [], true);

        return $evaluator;
    }

    protected function makeCommitteeHead(Program $program): User
    {
        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, (int) $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        return $head;
    }

    public function test_preview_reports_candidate_and_capacity_counts(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeSession($program);
        $head = $this->makeCommitteeHead($program);

        $this->makeProject($program, $session);
        $this->makeProject($program, $session);
        $this->makeEvaluator($program);
        $this->makeEvaluator($program);

        Sanctum::actingAs($head);

        $response = $this->postJson('/api/evaluator-assignment/preview', [
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_candidates', 2);
        $response->assertJsonPath('data.available_evaluators', 2);
        // "assigned_count" in a dry run means "would be assigned if committed now".
        $response->assertJsonPath('data.assigned_count', 2);
        $response->assertJsonPath('data.skipped_count', 0);
        $response->assertJsonPath('data.dry_run', true);

        // Preview must not have actually written anything to the database.
        $this->assertDatabaseCount('project_evaluators', 0);
    }

    public function test_auto_assign_assigns_evaluators_to_all_candidates(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeSession($program);
        $head = $this->makeCommitteeHead($program);

        $projectA = $this->makeProject($program, $session);
        $projectB = $this->makeProject($program, $session);
        $this->makeEvaluator($program);
        $this->makeEvaluator($program);
        $this->makeEvaluator($program);

        Sanctum::actingAs($head);

        $response = $this->postJson('/api/evaluator-assignment/auto-assign', [
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.assigned_count', 2);
        $response->assertJsonPath('data.skipped_count', 0);

        $this->assertEquals(2, $projectA->fresh()->evaluators()->count());
        $this->assertEquals(2, $projectB->fresh()->evaluators()->count());
        $this->assertEquals('evaluator_review', $projectA->fresh()->workflow_stage);
    }

    public function test_auto_assign_never_picks_projects_own_supervisor(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeSession($program);
        $head = $this->makeCommitteeHead($program);

        $supervisorEvaluator = $this->makeEvaluator($program);
        $supervisorEvaluator->assignRole('supervisor');
        $project = $this->makeProject($program, $session, $supervisorEvaluator);
        $this->makeEvaluator($program);
        $this->makeEvaluator($program);

        Sanctum::actingAs($head);

        $this->postJson('/api/evaluator-assignment/auto-assign', [
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ])->assertOk();

        $assignedIds = $project->fresh()->evaluators()->pluck('evaluator_id')->all();
        $this->assertNotContains($supervisorEvaluator->id, $assignedIds);
    }

    public function test_auto_assign_skips_projects_when_evaluator_capacity_is_insufficient(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeSession($program);
        $head = $this->makeCommitteeHead($program);

        $this->makeProject($program, $session);
        $this->makeProject($program, $session);
        // Only one evaluator with capacity for a single project — but min_evaluators is 2 per
        // project, so nothing can be fully assigned; both projects should be skipped.
        $this->makeEvaluator($program, ['evaluation_limit_proposal' => 1]);

        Sanctum::actingAs($head);

        $response = $this->postJson('/api/evaluator-assignment/auto-assign', [
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.assigned_count', 0);
        $response->assertJsonPath('data.skipped_count', 2);
        $this->assertDatabaseCount('project_evaluators', 0);
    }

    public function test_unauthorized_user_cannot_preview_or_assign(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeSession($program);

        $student = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $this->postJson('/api/evaluator-assignment/preview', [
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ])->assertStatus(403);

        $this->postJson('/api/evaluator-assignment/auto-assign', [
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ])->assertStatus(403);
    }

    public function test_pending_list_returns_candidate_projects_with_emails(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeSession($program);
        $head = $this->makeCommitteeHead($program);

        $project = $this->makeProject($program, $session);

        Sanctum::actingAs($head);

        $response = $this->getJson('/api/evaluator-assignment/pending?'.http_build_query([
            'proposal_session_id' => $session->id,
            'phase' => 'proposal',
        ]));

        $response->assertOk();
        $ids = collect($response->json('data.projects'))->pluck('id')->all();
        $this->assertContains($project->id, $ids);
        $this->assertNotNull(collect($response->json('data.projects'))->firstWhere('id', $project->id)['leader_email']);
    }
}
