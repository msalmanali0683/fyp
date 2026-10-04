<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommitteeHeadBulkApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function makeProposalAwaitingHead(Program $program): Project
    {
        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        return Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Proposal Awaiting Head '.uniqid(),
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_head_approval',
            'status' => 'active',
        ]);
    }

    protected function makePhaseAwaitingHead(Program $program, string $phase): Project
    {
        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Phase Awaiting Head '.uniqid(),
            'current_phase' => $phase,
            'workflow_stage' => 'approved',
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $p) {
            ProjectPhase::create([
                'project_id' => $project->id,
                'phase' => $p,
                'status' => $p === $phase ? 'submitted' : 'draft',
                'workflow_stage' => $p === $phase ? 'committee_head_approval' : 'draft',
            ]);
        }

        return $project;
    }

    public function test_committee_head_can_approve_all_pending_proposals_and_phases_at_once(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $proposal = $this->makeProposalAwaitingHead($program);
        $phaseProject = $this->makePhaseAwaitingHead($program, 'phase_1');

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');

        Sanctum::actingAs($head);

        $response = $this->postJson('/api/projects/committee-head/approve-all');

        $response->assertOk()->assertJsonPath('data.approved_count', 2);

        $this->assertSame('approved', $proposal->fresh()->workflow_stage);
        $this->assertSame('approved', $phaseProject->fresh()->phase('phase_1')->workflow_stage);
    }

    public function test_bulk_approval_is_scoped_to_heads_own_program(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();
        $dsProgram = Program::where('code', 'DS')->firstOrFail();

        $inScope = $this->makeProposalAwaitingHead($csProgram);
        $outOfScope = $this->makeProposalAwaitingHead($dsProgram);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $head->assignRole('fyp-committee-head');

        Sanctum::actingAs($head);

        $this->postJson('/api/projects/committee-head/approve-all')
            ->assertOk()
            ->assertJsonPath('data.approved_count', 1);

        $this->assertSame('approved', $inScope->fresh()->workflow_stage);
        $this->assertSame('committee_head_approval', $outOfScope->fresh()->workflow_stage);
    }

    public function test_non_head_cannot_bulk_approve(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $proposal = $this->makeProposalAwaitingHead($program);

        $committeeMember = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $committeeMember->assignRole('fyp-committee-member');

        Sanctum::actingAs($committeeMember);

        $this->postJson('/api/projects/committee-head/approve-all')->assertStatus(422);

        $this->assertSame('committee_head_approval', $proposal->fresh()->workflow_stage);
    }
}
