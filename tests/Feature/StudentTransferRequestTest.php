<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentTransferRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function makeProjectWithMember(string $phase = 'phase_1'): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $member = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $member->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Source Project '.uniqid(),
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => $phase,
            'workflow_stage' => 'approved',
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $p) {
            ProjectPhase::create(['project_id' => $project->id, 'phase' => $p, 'status' => 'draft']);
        }

        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        return [$program, $leader, $member, $project];
    }

    protected function makeTargetProject(Program $program, string $phase): array
    {
        $targetLeader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $targetLeader->assignRole('student');

        $project = Project::create([
            'student_id' => $targetLeader->id,
            'program_id' => $program->id,
            'title' => 'Target Project '.uniqid(),
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => $phase,
            'workflow_stage' => 'approved',
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $p) {
            ProjectPhase::create(['project_id' => $project->id, 'phase' => $p, 'status' => 'draft']);
        }

        return [$targetLeader, $project];
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

    public function test_team_member_can_request_transfer_permission(): void
    {
        [$program, , $member, $sourceProject] = $this->makeProjectWithMember('phase_1');

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/transfer-requests', [
            'reason' => 'Prefer a team closer to my research interest.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('student_transfer_requests', [
            'student_id' => $member->id,
            'from_project_id' => $sourceProject->id,
            'to_project_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_leader_cannot_request_transfer(): void
    {
        [, $leader] = $this->makeProjectWithMember('phase_1');

        Sanctum::actingAs($leader);

        $this->postJson('/api/transfer-requests', [
            'reason' => 'Trying to transfer as leader.',
        ])->assertStatus(422);
    }

    public function test_committee_head_is_notified_and_approval_reveals_eligible_targets(): void
    {
        [$program, , $member] = $this->makeProjectWithMember('phase_1');
        [, $targetProject] = $this->makeTargetProject($program, 'phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', [
            'reason' => 'Prefer a team closer to my research interest.',
        ])->json('data');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $head->id,
            'title' => 'New Student Transfer Request',
        ]);

        // Not yet approved — no targets should be visible.
        $before = $this->getJson('/api/transfer-requests/targets')->json('data');
        $this->assertEmpty($before);

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => true,
        ])->assertOk()->assertJsonPath('data.status', 'eligible');

        Sanctum::actingAs($member);
        $targets = $this->getJson('/api/transfer-requests/targets')->json('data');
        $this->assertNotEmpty($targets);
        $this->assertEquals($targetProject->id, $targets[0]['id']);
    }

    public function test_cannot_select_target_before_admin_approval(): void
    {
        [$program, , $member] = $this->makeProjectWithMember('phase_1');
        [, $targetProject] = $this->makeTargetProject($program, 'phase_1');

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', [
            'reason' => 'Reason.',
        ])->json('data');

        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $targetProject->id,
        ])->assertStatus(422);
    }

    public function test_cannot_select_target_in_different_phase(): void
    {
        [$program, , $member, $sourceProject] = $this->makeProjectWithMember('phase_1');
        [, $targetProject] = $this->makeTargetProject($program, 'phase_2');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $targetProject->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('project_members', [
            'project_id' => $sourceProject->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
    }

    public function test_full_flow_leader_accepts_join_request(): void
    {
        [$program, , $member, $sourceProject] = $this->makeProjectWithMember('phase_1');
        [$targetLeader, $targetProject] = $this->makeTargetProject($program, 'phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $targetProject->id,
        ])->assertOk()->assertJsonPath('data.status', 'pending_leader');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $targetLeader->id,
            'title' => 'Request to Join Your Group',
        ]);

        Sanctum::actingAs($targetLeader);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => true,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $sourceProject->id,
            'user_id' => $member->id,
            'status' => 'removed',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $targetProject->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
    }

    public function test_leader_rejection_loops_student_back_to_eligible_for_another_group(): void
    {
        [$program, , $member, $sourceProject] = $this->makeProjectWithMember('phase_1');
        [$firstLeader, $firstTarget] = $this->makeTargetProject($program, 'phase_1');
        [$secondLeader, $secondTarget] = $this->makeTargetProject($program, 'phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $firstTarget->id,
        ])->assertOk();

        Sanctum::actingAs($firstLeader);
        $rejected = $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => false,
            'comments' => 'No room for another member right now.',
        ])->assertOk()->assertJsonPath('data.status', 'eligible')->json('data');

        $this->assertNull($rejected['to_project_id']);

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $secondTarget->id,
        ])->assertOk()->assertJsonPath('data.status', 'pending_leader');

        Sanctum::actingAs($secondLeader);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => true,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $sourceProject->id,
            'user_id' => $member->id,
            'status' => 'removed',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $secondTarget->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
    }

    public function test_committee_head_can_decide_join_request_on_behalf_of_leader(): void
    {
        [$program, , $member, $sourceProject] = $this->makeProjectWithMember('phase_1');
        [, $targetProject] = $this->makeTargetProject($program, 'phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $targetProject->id,
        ])->assertOk();

        // The committee head decides the join request without the leader responding.
        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => true,
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $targetProject->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
    }

    public function test_committee_head_can_reject_initial_request_leaving_membership_unchanged(): void
    {
        [$program, , $member, $sourceProject] = $this->makeProjectWithMember('phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => false,
            'comments' => 'Not enough justification.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('project_members', [
            'project_id' => $sourceProject->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
    }

    public function test_unrelated_user_cannot_decide_admin_stage(): void
    {
        [, , $member] = $this->makeProjectWithMember('phase_1');

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        $outsider = User::factory()->create(['status' => 'active']);
        $outsider->assignRole('faculty');

        Sanctum::actingAs($outsider);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => true,
        ])->assertStatus(422);
    }

    public function test_unrelated_user_cannot_decide_leader_stage(): void
    {
        [$program, , $member] = $this->makeProjectWithMember('phase_1');
        [, $targetProject] = $this->makeTargetProject($program, 'phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $targetProject->id,
        ])->assertOk();

        $outsider = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $outsider->assignRole('student');

        Sanctum::actingAs($outsider);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", [
            'approve' => true,
        ])->assertStatus(422);
    }

    public function test_dashboard_next_actions_include_pending_admin_stage_request(): void
    {
        [$program, , $member] = $this->makeProjectWithMember('phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->assertCreated();

        Sanctum::actingAs($head);
        $response = $this->getJson('/api/dashboard/next-actions')->assertOk();

        $keys = collect($response->json('data.actions'))->pluck('key')->all();
        $this->assertContains('student_transfer_requests', $keys);
    }

    public function test_dashboard_does_not_count_eligible_requests_as_needing_admin_decision(): void
    {
        [$program, , $member] = $this->makeProjectWithMember('phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        $response = $this->getJson('/api/dashboard/next-actions')->assertOk();
        $keys = collect($response->json('data.actions'))->pluck('key')->all();
        $this->assertNotContains('student_transfer_requests', $keys);
    }

    public function test_dashboard_shows_incoming_join_request_to_target_leader(): void
    {
        [$program, , $member] = $this->makeProjectWithMember('phase_1');
        [$targetLeader, $targetProject] = $this->makeTargetProject($program, 'phase_1');
        $head = $this->makeCommitteeHead($program);

        Sanctum::actingAs($member);
        $created = $this->postJson('/api/transfer-requests', ['reason' => 'Reason.'])->json('data');

        Sanctum::actingAs($head);
        $this->postJson("/api/transfer-requests/{$created['id']}/decide", ['approve' => true])->assertOk();

        Sanctum::actingAs($member);
        $this->postJson("/api/transfer-requests/{$created['id']}/select-target", [
            'to_project_id' => $targetProject->id,
        ])->assertOk();

        Sanctum::actingAs($targetLeader);
        $response = $this->getJson('/api/dashboard/next-actions')->assertOk();
        $keys = collect($response->json('data.actions'))->pluck('key')->all();
        $this->assertContains('incoming_transfer_join_requests', $keys);
    }
}
