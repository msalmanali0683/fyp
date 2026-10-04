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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectPhaseAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
        Storage::fake('public');
    }

    public function test_project_leader_can_upload_phase_attachment(): void
    {
        [$leader, $project] = $this->makePhaseOneProject();

        Sanctum::actingAs($leader);

        $response = $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $this->assertNotNull($project->fresh()->phase('phase_1')->attachment);
    }

    public function test_team_member_cannot_upload_phase_attachment(): void
    {
        [$leader, $project, $member] = $this->makePhaseOneProject(withMember: true);

        Sanctum::actingAs($member);

        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1.pdf', 100, 'application/pdf'),
        ])->assertForbidden()
            ->assertJsonPath('message', 'Only the project leader can upload phase files.');

        $this->assertNull($project->fresh()->phase('phase_1')->attachment);
    }

    public function test_team_member_session_context_cannot_edit_phase_deliverable(): void
    {
        [$leader, $project, $member] = $this->makePhaseOneProject(withMember: true);

        Sanctum::actingAs($member);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk()
            ->assertJsonPath('data.registration.session.can_edit_phase_deliverable', false);
    }

    protected function makePhaseOneProject(bool $withMember = false): array
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
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
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
            'title' => 'Phase One Project',
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

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $leader->id,
            'role' => 'leader',
            'status' => 'active',
        ]);

        $member = null;

        if ($withMember) {
            $member = User::factory()->create([
                'status' => 'active',
                'program_id' => $program->id,
                'department_id' => $program->department_id,
                'session' => 'SP2026',
                'is_proposal_enrolled' => true,
            ]);
            $member->assignRole('student');

            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $member->id,
                'role' => 'member',
                'status' => 'active',
            ]);
        }

        return $withMember ? [$leader, $project, $member] : [$leader, $project];
    }
}
