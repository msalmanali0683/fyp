<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\SupervisorChangeRequest;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupervisorChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function createSupervisorChangeProject(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $programScope = app(ProgramScopeService::class);

        $leader = User::factory()->create([
            'status' => 'active',
            'is_proposal_enrolled' => true,
            'program_id' => $program->id,
        ]);
        $leader->assignRole('student');
        $programScope->assignUserProgram($leader, $program, true);

        $currentSupervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'supervision_limit_proposal' => 10,
        ]);
        $currentSupervisor->assignRole(['faculty', 'supervisor']);
        $programScope->syncMembership($currentSupervisor, $program->id, [], true);

        $newSupervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'supervision_limit_proposal' => 10,
        ]);
        $newSupervisor->assignRole(['faculty', 'supervisor']);
        $programScope->syncMembership($newSupervisor, $program->id, [], true);

        $head = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
        ]);
        $head->assignRole('fyp-committee-head');
        $programScope->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        $member = User::factory()->create([
            'status' => 'active',
            'is_proposal_enrolled' => true,
            'program_id' => $program->id,
        ]);
        $member->assignRole('student');
        $programScope->assignUserProgram($member, $program, true);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'supervisor_id' => $currentSupervisor->id,
            'supervisor_status' => 'accepted',
            'title' => 'Supervisor Change Test Project',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create([
                'project_id' => $project->id,
                'phase' => $phase,
                'status' => 'draft',
            ]);
        }

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $leader->id,
            'role' => 'leader',
            'status' => 'active',
        ]);

        return compact(
            'leader',
            'member',
            'currentSupervisor',
            'newSupervisor',
            'head',
            'project',
            'program'
        );
    }

    public function test_student_leader_can_submit_supervisor_change_request(): void
    {
        [
            'leader' => $leader,
            'currentSupervisor' => $currentSupervisor,
            'newSupervisor' => $newSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);

        $response = $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Need a supervisor with closer research alignment.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.supervisor_change_request.overall_status', 'pending')
            ->assertJsonPath('data.supervisor_change_request.new_supervisor.id', $newSupervisor->id)
            ->assertJsonPath('data.can_request_supervisor_change', false);

        $this->assertDatabaseHas('supervisor_change_requests', [
            'project_id' => $project->id,
            'requested_by' => $leader->id,
            'current_supervisor_id' => $currentSupervisor->id,
            'new_supervisor_id' => $newSupervisor->id,
            'overall_status' => 'pending',
        ]);
    }

    public function test_committee_head_is_notified_immediately_when_request_is_submitted(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'head' => $head,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Need a supervisor with closer research alignment.',
        ])->assertCreated();

        // The committee head should already have a notification, before either
        // supervisor has responded — not just once both sides have answered.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'title' => 'New Supervisor Change Request',
        ]);
    }

    public function test_admin_can_list_active_supervisor_change_requests(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Need a supervisor with closer research alignment.',
        ])->assertCreated();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/supervisor-change-requests')->assertOk();

        $response->assertJsonPath('data.0.project.id', $project->id);
        $response->assertJsonPath('data.0.overall_status', 'pending');
        $response->assertJsonPath('data.0.viewer_can_manage', true);
    }

    public function test_committee_head_only_sees_supervisor_change_requests_within_their_program(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'head' => $head,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Need a supervisor with closer research alignment.',
        ])->assertCreated();

        $dsProgram = Program::where('code', 'DS')->firstOrFail();
        $outsideHead = User::factory()->create(['status' => 'active', 'program_id' => $dsProgram->id]);
        $outsideHead->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($outsideHead, (int) $dsProgram->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($outsideHead);
        $this->getJson('/api/supervisor-change-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($head);
        $this->getJson('/api/supervisor-change-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_dashboard_next_actions_include_pending_supervisor_change_request(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'head' => $head,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Need a supervisor with closer research alignment.',
        ])->assertCreated();

        Sanctum::actingAs($head);
        $response = $this->getJson('/api/dashboard/next-actions')->assertOk();

        $keys = collect($response->json('data.actions'))->pluck('key')->all();
        $this->assertContains('supervisor_change_requests', $keys);
    }

    public function test_student_leader_can_submit_supervisor_change_request_during_phase_1(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        $newSupervisor->update(['supervision_limit_phase_1' => 10]);
        $project->update(['current_phase' => 'phase_1']);

        Sanctum::actingAs($leader);

        $show = $this->getJson("/api/projects/{$project->id}")->assertOk();
        $show->assertJsonPath('data.can_request_supervisor_change', true);

        $response = $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Current supervisor is unavailable during Phase 1.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.supervisor_change_request.overall_status', 'pending');

        $this->assertDatabaseHas('supervisor_change_requests', [
            'project_id' => $project->id,
            'new_supervisor_id' => $newSupervisor->id,
            'overall_status' => 'pending',
        ]);
    }

    public function test_both_supervisors_accept_and_authority_approves_changes_supervisor(): void
    {
        [
            'leader' => $leader,
            'currentSupervisor' => $currentSupervisor,
            'newSupervisor' => $newSupervisor,
            'head' => $head,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Research area mismatch.',
        ])->assertCreated();

        $changeRequest = SupervisorChangeRequest::where('project_id', $project->id)->firstOrFail();

        Sanctum::actingAs($currentSupervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/respond", [
            'accept' => true,
        ])->assertOk()
            ->assertJsonPath('data.supervisor_change_request.current_supervisor_status', 'accepted');

        Sanctum::actingAs($newSupervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/respond", [
            'accept' => true,
        ])->assertOk()
            ->assertJsonPath('data.supervisor_change_request.overall_status', 'awaiting_authority');

        Sanctum::actingAs($head);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/decide", [
            'approve' => true,
            'comments' => 'Approved by committee head.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.supervisor.id', $newSupervisor->id)
            ->assertJsonPath('data.supervisor_change_request', null);

        $project->refresh();
        $this->assertSame($newSupervisor->id, $project->supervisor_id);
        $this->assertSame('accepted', $project->supervisor_status);

        $changeRequest->refresh();
        $this->assertSame('approved', $changeRequest->overall_status);
    }

    public function test_both_supervisors_respond_and_authority_declines_keeps_supervisor(): void
    {
        [
            'leader' => $leader,
            'currentSupervisor' => $currentSupervisor,
            'newSupervisor' => $newSupervisor,
            'head' => $head,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Scheduling conflicts.',
        ])->assertCreated();

        $changeRequest = SupervisorChangeRequest::where('project_id', $project->id)->firstOrFail();

        Sanctum::actingAs($currentSupervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/respond", [
            'accept' => true,
        ])->assertOk();

        Sanctum::actingAs($newSupervisor);
        $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/respond", [
            'accept' => true,
        ])->assertOk();

        Sanctum::actingAs($head);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/decide", [
            'approve' => false,
            'comments' => 'Change not justified.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.supervisor.id', $currentSupervisor->id)
            ->assertJsonPath('data.supervisor_change_request', null);

        $project->refresh();
        $this->assertSame($currentSupervisor->id, $project->supervisor_id);

        $changeRequest->refresh();
        $this->assertSame('declined', $changeRequest->overall_status);
    }

    public function test_committee_head_can_respond_on_behalf_of_supervisor(): void
    {
        [
            'leader' => $leader,
            'currentSupervisor' => $currentSupervisor,
            'newSupervisor' => $newSupervisor,
            'head' => $head,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Supervisor unavailable.',
        ])->assertCreated();

        $changeRequest = SupervisorChangeRequest::where('project_id', $project->id)->firstOrFail();

        Sanctum::actingAs($head);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor-change/{$changeRequest->id}/respond", [
            'accept' => true,
            'which' => 'current',
            'comments' => 'Accepted on behalf of current supervisor.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.supervisor_change_request.current_supervisor_status', 'accepted')
            ->assertJsonPath('data.viewer_can_manage_supervisor_change', true);

        $changeRequest->refresh();
        $this->assertSame('accepted', $changeRequest->current_supervisor_status);
        $this->assertSame($head->id, $changeRequest->current_supervisor_responded_by);
    }

    public function test_cannot_request_same_supervisor(): void
    {
        [
            'leader' => $leader,
            'currentSupervisor' => $currentSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);

        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $currentSupervisor->id,
            'reason' => 'No real change.',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['new_supervisor_id']);
    }

    public function test_reason_is_required(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);

        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_non_leader_cannot_submit_request(): void
    {
        [
            'member' => $member,
            'newSupervisor' => $newSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($member);

        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Attempt by non-leader.',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['project']);
    }

    public function test_duplicate_active_request_is_blocked(): void
    {
        [
            'leader' => $leader,
            'newSupervisor' => $newSupervisor,
            'project' => $project,
        ] = $this->createSupervisorChangeProject();

        Sanctum::actingAs($leader);

        $payload = [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'First request.',
        ];

        $this->postJson("/api/projects/{$project->id}/supervisor-change", $payload)->assertCreated();

        $this->postJson("/api/projects/{$project->id}/supervisor-change", [
            'new_supervisor_id' => $newSupervisor->id,
            'reason' => 'Second request.',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['project']);
    }
}
