<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentPortalPhaseMismatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    /**
     * A project left pending when the FYP office completes Phase 1 for the
     * whole session stays at current_phase = phase_1 until it's individually
     * approved (see ProjectPhaseTransitionService::advanceApprovedPhase1ProjectsToPhase2,
     * which only ever runs as part of that one-time batch action). The session
     * itself moves on to Phase 2 regardless. The student's portal must keep
     * treating Phase 1 as their own active phase, not silently lock them out
     * because the session has moved ahead of them.
     */
    public function test_student_left_behind_in_phase_1_can_still_edit_after_session_moves_to_phase_2(): void
    {
        [$leader] = $this->makeLeaderWithPhase1Project(sessionLifecycle: ProposalSession::LIFECYCLE_PHASE_2);

        Sanctum::actingAs($leader);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk()
            ->assertJsonPath('data.registration.session.deliverable_phase', 'phase_1')
            ->assertJsonPath('data.registration.session.can_edit_phase_deliverable', true)
            ->assertJsonPath('data.registration.session.awaiting_next_phase', true)
            ->assertJsonPath('data.registration.session.student_phase', 'phase_1');

        $this->assertStringContainsString(
            'Phase-1',
            $response->json('data.registration.session.awaiting_next_phase_message')
        );
    }

    public function test_student_in_sync_with_session_phase_is_not_marked_awaiting(): void
    {
        [$leader] = $this->makeLeaderWithPhase1Project(sessionLifecycle: ProposalSession::LIFECYCLE_PHASE_1);

        Sanctum::actingAs($leader);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk()
            ->assertJsonPath('data.registration.session.deliverable_phase', 'phase_1')
            ->assertJsonPath('data.registration.session.can_edit_phase_deliverable', true)
            ->assertJsonPath('data.registration.session.awaiting_next_phase', false)
            ->assertJsonPath('data.registration.session.submission_kind_label', 'Phase 1 submission window');
    }

    protected function makeLeaderWithPhase1Project(string $sessionLifecycle): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'SP2026',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026',
            'is_submission_open' => true,
            'is_fully_locked' => false,
            'status' => 'active',
            'lifecycle_phase' => $sessionLifecycle,
        ]);

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');
        app(ProgramScopeService::class)->syncMembership($supervisor, (int) $program->id, [], true);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Left Behind Project',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        ProjectPhase::create([
            'project_id' => $project->id,
            'phase' => 'proposal',
            'status' => 'approved',
            'workflow_stage' => 'approved',
        ]);

        ProjectPhase::create([
            'project_id' => $project->id,
            'phase' => 'phase_1',
            'status' => 'draft',
            'workflow_stage' => 'draft',
        ]);

        return [$leader, $project, $session];
    }
}
