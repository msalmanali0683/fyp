<?php

namespace Tests\Feature;

use App\Models\EvaluatorReview;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectPhase;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EvaluatorDashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_evaluator_assigned_list_uses_current_phase_review_not_proposal_review(): void
    {
        [$evaluator, $project] = $this->makePhaseOneProjectWithProposalReview();

        Sanctum::actingAs($evaluator);

        $assigned = $this->getJson('/api/projects?list_context=evaluator&evaluator_scope=assigned');
        $completed = $this->getJson('/api/projects?list_context=evaluator&evaluator_scope=completed');

        $assigned->assertOk();
        $completed->assertOk();

        $this->assertContains($project->id, collect($assigned->json('data.projects'))->pluck('id')->all());
        $this->assertNotContains($project->id, collect($completed->json('data.projects'))->pluck('id')->all());
    }

    public function test_evaluator_completed_list_shows_project_after_current_phase_review(): void
    {
        [$evaluator, $project] = $this->makePhaseOneProjectWithProposalReview();

        EvaluatorReview::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'fyp_phase' => 'phase_1',
            'decision' => 'accepted',
            'reviewed_at' => now(),
        ]);

        Sanctum::actingAs($evaluator);

        $assigned = $this->getJson('/api/projects?list_context=evaluator&evaluator_scope=assigned');
        $completed = $this->getJson('/api/projects?list_context=evaluator&evaluator_scope=completed');

        $assigned->assertOk();
        $completed->assertOk();

        $this->assertNotContains($project->id, collect($assigned->json('data.projects'))->pluck('id')->all());
        $this->assertContains($project->id, collect($completed->json('data.projects'))->pluck('id')->all());
    }

    protected function makePhaseOneProjectWithProposalReview(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
        ]);

        $evaluator = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $evaluator->assignRole('evaluator');
        app(ProgramScopeService::class)->syncMembership($evaluator, (int) $program->id, [], true);

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'SP2026',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Phase Scope Test',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        ProjectPhase::create([
            'project_id' => $project->id,
            'phase' => 'phase_1',
            'status' => 'submitted',
            'workflow_stage' => 'evaluator_review',
        ]);

        ProjectEvaluator::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'assigned_by' => $supervisor->id,
        ]);

        EvaluatorReview::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'fyp_phase' => 'proposal',
            'decision' => 'accepted',
            'reviewed_at' => now()->subDay(),
        ]);

        return [$evaluator, $project];
    }
}
