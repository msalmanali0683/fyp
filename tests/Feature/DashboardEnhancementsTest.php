<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_student_next_actions_include_register_proposal_when_no_project(): void
    {
        $student = $this->makeStudent();

        Sanctum::actingAs($student);

        $this->getJson('/api/dashboard/next-actions')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'student_register',
                'title' => 'Submit your proposal',
            ]);
    }

    public function test_supervisor_role_widgets_return_active_project_counts(): void
    {
        [$supervisor, $project] = $this->makeSupervisedProject();

        Sanctum::actingAs($supervisor);

        $this->getJson('/api/dashboard/role-widgets')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'supervised_total',
                'label' => 'Active Supervised Projects',
                'value' => '1',
            ]);
    }

    public function test_notifications_unread_count_endpoint(): void
    {
        $student = $this->makeStudent();

        Notification::create([
            'user_id' => $student->id,
            'title' => 'Test',
            'message' => 'Unread notification',
            'type' => 'info',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $student->id,
            'title' => 'Read',
            'message' => 'Already read',
            'type' => 'info',
            'is_read' => true,
            'read_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_committee_member_can_export_projects_csv(): void
    {
        [$member, $project] = $this->makeCommitteeProject();

        Sanctum::actingAs($member);

        $response = $this->get('/api/projects/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($project->title, $response->streamedContent());
    }

    public function test_committee_final_queue_counts_deliverable_phase_projects(): void
    {
        [$member, $project] = $this->makePhaseOneCommitteeFinalProject();

        Sanctum::actingAs($member);

        $this->getJson('/api/dashboard/role-widgets')
            ->assertOk()
            ->assertJsonPath('data.widgets.0.key', 'committee_pending')
            ->assertJsonPath('data.widgets.0.value', '1');

        $this->getJson('/api/dashboard/next-actions')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'committee_final',
                'count' => 1,
            ]);
    }

    protected function makeStudent(): User
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $student->assignRole('student');
        app(ProgramScopeService::class)->syncMembership($student, (int) $program->id, [], true);

        return $student;
    }

    protected function makeSupervisedProject(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');
        app(ProgramScopeService::class)->syncMembership($supervisor, (int) $program->id, [], true);

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $leader->assignRole('student');

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026-DASH',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => 'proposal',
        ]);

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => $supervisor->id,
            'proposal_session_id' => $session->id,
            'program_id' => $program->id,
            'title' => 'Dashboard Widget Project',
            'status' => 'active',
            'current_phase' => 'proposal',
            'workflow_stage' => 'supervisor_pending',
        ]);

        return [$supervisor, $project];
    }

    protected function makeCommitteeProject(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $member = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $member->assignRole('fyp-committee-member');
        app(ProgramScopeService::class)->syncMembership($member, (int) $program->id, [], true);

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $leader->assignRole('student');

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026-EXPORT',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => 'proposal',
        ]);

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => $supervisor->id,
            'proposal_session_id' => $session->id,
            'program_id' => $program->id,
            'title' => 'Exportable Project Alpha',
            'status' => 'active',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
        ]);

        return [$member, $project];
    }

    protected function makePhaseOneCommitteeFinalProject(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $member = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $member->assignRole('fyp-committee-member');
        app(ProgramScopeService::class)->syncMembership($member, (int) $program->id, [], true);

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $leader->assignRole('student');

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026-FINAL',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => 'phase_1',
        ]);

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => $supervisor->id,
            'proposal_session_id' => $session->id,
            'program_id' => $program->id,
            'title' => 'Phase 1 Committee Final Project',
            'status' => 'active',
            'current_phase' => 'phase_1',
            'workflow_stage' => 'approved',
        ]);

        $project->phases()->create([
            'phase' => 'phase_1',
            'status' => 'submitted',
            'workflow_stage' => 'committee_final',
            'submitted_at' => now(),
        ]);

        return [$member, $project];
    }

    public function test_project_list_needs_action_filter_for_committee_final(): void
    {
        [$member, $project] = $this->makePhaseOneCommitteeFinalProject();

        Sanctum::actingAs($member);

        $this->getJson('/api/projects?needs_action=committee_final')
            ->assertOk()
            ->assertJsonCount(1, 'data.projects')
            ->assertJsonPath('data.projects.0.id', $project->id);
    }

    public function test_student_next_actions_include_deliverable_revision(): void
    {
        $student = $this->makeStudent();
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026-REV',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => 'phase_1',
        ]);

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');

        $project = Project::create([
            'student_id' => $student->id,
            'supervisor_id' => $supervisor->id,
            'proposal_session_id' => $session->id,
            'program_id' => $program->id,
            'title' => 'Revision Project',
            'status' => 'active',
            'current_phase' => 'phase_1',
            'workflow_stage' => 'approved',
        ]);

        $project->phases()->create([
            'phase' => 'phase_1',
            'status' => 'submitted',
            'workflow_stage' => 'revision_required',
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/dashboard/next-actions')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'student_revision',
                'title' => 'Resubmit after revision',
            ]);
    }
}
