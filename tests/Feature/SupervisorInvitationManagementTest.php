<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupervisorInvitationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function createPendingSupervisionProject(): array
    {
        $leader = User::factory()->create(['status' => 'active', 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $supervisor = User::factory()->create(['status' => 'active']);
        $supervisor->assignRole(['faculty', 'supervisor']);

        $head = User::factory()->create(['status' => 'active']);
        $head->assignRole('fyp-committee-head');

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'pending',
            'title' => 'Supervisor Invitation Test Project',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'supervisor_pending',
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

        return compact('leader', 'supervisor', 'head', 'project');
    }

    public function test_committee_head_can_accept_supervision_on_behalf_of_supervisor(): void
    {
        ['head' => $head, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/projects/{$project->id}/supervisor/respond", [
            'accept' => true,
            'feedback' => 'Approved by committee head.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'committee_review')
            ->assertJsonPath('data.supervisor_status', 'accepted');

        $project->refresh();
        $this->assertSame('committee_review', $project->workflow_stage);
    }

    public function test_user_with_permission_can_decline_supervision_on_behalf_of_supervisor(): void
    {
        ['supervisor' => $supervisor, 'project' => $project] = $this->createPendingSupervisionProject();

        $staff = User::factory()->create(['status' => 'active']);
        $staff->assignRole('fyp-committee-member');
        Permission::firstOrCreate(['name' => 'manage supervisor invitations']);
        Role::firstOrCreate(['name' => 'fyp-committee-member'])->givePermissionTo('manage supervisor invitations');
        $staff->givePermissionTo('manage supervisor invitations');

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/projects/{$project->id}/supervisor/respond", [
            'accept' => false,
            'feedback' => 'Supervisor unavailable this semester.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'supervisor_rejected')
            ->assertJsonPath('data.supervisor_status', 'rejected');

        $project->refresh();
        $this->assertSame('supervisor_rejected', $project->workflow_stage);
        $this->assertSame('Supervisor unavailable this semester.', $project->supervisor_rejection_feedback);
    }

    public function test_assigned_supervisor_still_can_respond_directly(): void
    {
        ['supervisor' => $supervisor, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($supervisor);

        $this->postJson("/api/projects/{$project->id}/supervisor/respond", [
            'accept' => true,
        ])->assertOk()->assertJsonPath('data.workflow_stage', 'committee_review');
    }

    public function test_leader_can_select_a_different_supervisor_after_a_rejection(): void
    {
        ['leader' => $leader, 'supervisor' => $supervisor, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor/respond", [
            'accept' => false,
            'feedback' => 'Not available this semester.',
        ])->assertOk()->assertJsonPath('data.workflow_stage', 'supervisor_rejected');

        $newSupervisor = User::factory()->create(['status' => 'active']);
        $newSupervisor->assignRole(['faculty', 'supervisor']);

        Sanctum::actingAs($leader);
        $response = $this->putJson("/api/projects/{$project->id}/supervisor", [
            'supervisor_id' => $newSupervisor->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'supervisor_pending')
            ->assertJsonPath('data.supervisor_status', 'pending')
            ->assertJsonPath('data.supervisor.id', $newSupervisor->id);

        $project->refresh();
        $this->assertSame($newSupervisor->id, $project->supervisor_id);
        $this->assertNull($project->supervisor_rejection_feedback);
    }

    public function test_supervisor_actor_name_is_hidden_from_student_but_visible_to_admin(): void
    {
        ['leader' => $leader, 'supervisor' => $supervisor, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor/respond", [
            'accept' => false,
            'feedback' => 'Not available this semester.',
        ])->assertOk();

        Sanctum::actingAs($leader);
        $studentView = $this->getJson("/api/projects/{$project->id}")->assertOk();
        $supervisorLog = collect($studentView->json('data.workflow_logs'))
            ->firstWhere('actor_role', 'supervisor');

        $this->assertNotNull($supervisorLog);
        $this->assertNull($supervisorLog['actor']);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $adminView = $this->getJson("/api/projects/{$project->id}")->assertOk();
        $supervisorLogForAdmin = collect($adminView->json('data.workflow_logs'))
            ->firstWhere('actor_role', 'supervisor');

        $this->assertSame($supervisor->name, $supervisorLogForAdmin['actor']['name']);
    }

    public function test_supervisor_can_request_revision_before_accepting_supervision(): void
    {
        ['supervisor' => $supervisor, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($supervisor);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor/request-revision", [
            'feedback' => 'Please expand the literature review before I proceed.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'revision_required')
            ->assertJsonPath('data.supervisor_status', 'accepted')
            ->assertJsonPath('data.supervisor_revision_feedback', 'Please expand the literature review before I proceed.');

        $project->refresh();
        $this->assertSame('revision_required', $project->workflow_stage);
        $this->assertSame('accepted', $project->supervisor_status);
        $this->assertSame($supervisor->id, $project->supervisor_id);
    }

    public function test_committee_head_can_request_revision_on_supervisors_behalf(): void
    {
        ['head' => $head, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/supervisor/request-revision", [
            'feedback' => 'Methodology section needs more detail.',
        ])->assertOk()->assertJsonPath('data.workflow_stage', 'revision_required');

        $project->refresh();
        $this->assertSame('revision_required', $project->workflow_stage);
        $this->assertSame('accepted', $project->supervisor_status);
    }

    public function test_supervisor_request_revision_requires_feedback(): void
    {
        ['supervisor' => $supervisor, 'project' => $project] = $this->createPendingSupervisionProject();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor/request-revision", [])
            ->assertStatus(422);

        $this->assertSame('supervisor_pending', $project->fresh()->workflow_stage);
    }

    public function test_supervisor_change_request_cannot_be_filed_before_supervisor_accepts(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createPendingSupervisionProject();

        $anotherSupervisor = User::factory()->create(['status' => 'active']);
        $anotherSupervisor->assignRole(['faculty', 'supervisor']);

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $anotherSupervisor->id,
            'reason' => 'Prefer a different supervisor.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('supervisor_change_requests', 0);
    }
}
