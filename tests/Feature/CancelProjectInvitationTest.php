<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancelProjectInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_student_leader_cannot_cancel_pending_invitation(): void
    {
        [$project, $invitation, $leader] = $this->makePendingInvitation();

        Sanctum::actingAs($leader);

        $this->deleteJson("/api/projects/{$project->id}/invitations/{$invitation->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invitation']);

        $this->assertSame('pending', $invitation->fresh()->status);
    }

    public function test_committee_head_can_cancel_pending_invitation(): void
    {
        [$project, $invitation] = $this->makePendingInvitation();

        $head = User::factory()->create(['status' => 'active']);
        $head->assignRole('fyp-committee-head');

        Sanctum::actingAs($head);

        $this->deleteJson("/api/projects/{$project->id}/invitations/{$invitation->id}")
            ->assertOk();

        $this->assertSame('rejected', $invitation->fresh()->status);
    }

    public function test_committee_head_can_accept_invitation_on_behalf_of_student(): void
    {
        [$project, $invitation, $leader, $invitee] = $this->makePendingInvitation();

        $head = User::factory()->create(['status' => 'active']);
        $head->assignRole('fyp-committee-head');

        Sanctum::actingAs($head);

        $this->postJson("/api/projects/{$project->id}/invitations/{$invitation->id}/accept-on-behalf", [
            'comments' => 'Accepted by committee head.',
        ])->assertOk();

        $invitation->refresh();
        $this->assertSame('accepted', $invitation->status);
        $this->assertTrue(
            \App\Models\ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('user_id', $invitee->id)
                ->exists()
        );
    }

    public function test_student_leader_cannot_accept_invitation_on_behalf(): void
    {
        [$project, $invitation, $leader] = $this->makePendingInvitation();

        Sanctum::actingAs($leader);

        $this->postJson("/api/projects/{$project->id}/invitations/{$invitation->id}/accept-on-behalf")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invitation']);

        $this->assertSame('pending', $invitation->fresh()->status);
    }

    public function test_leader_can_invite_a_different_student_after_a_rejection(): void
    {
        [$project, $invitation, $leader] = $this->makePendingInvitation();

        Sanctum::actingAs($invitation->invitee);
        $this->postJson("/api/proposals/invitations/{$invitation->id}/respond", [
            'response' => 'rejected',
            'comments' => 'Not interested in this project.',
        ])->assertOk();

        $this->assertSame('rejected', $invitation->fresh()->status);

        $program = Program::where('code', 'CS')->firstOrFail();
        $anotherStudent = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $anotherStudent->assignRole('student');

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/invitations", [
            'invitee_ids' => [$anotherStudent->id],
        ])->assertOk();

        $this->assertTrue(
            ProjectInvitation::query()
                ->where('project_id', $project->id)
                ->where('invitee_id', $anotherStudent->id)
                ->where('status', 'pending')
                ->exists()
        );
    }

    /**
     * Reproduces a real reported case: the leader invites more candidates than
     * strictly needed; one rejects but the team still hits its minimum size
     * once the others accept, auto-advancing past invitations_pending
     * (checkGroupComplete()) before the leader gets a chance to replace the
     * student who rejected. The leader must still be able to invite a
     * replacement afterward, as long as the team isn't yet at max and a
     * supervisor hasn't accepted.
     */
    public function test_leader_can_invite_a_replacement_after_team_auto_advances_past_invitations_pending(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Team Formation Project',
            'workflow_stage' => 'invitations_pending',
            'status' => 'active',
            'current_phase' => 'proposal',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $leader->id,
            'role' => 'leader',
        ]);

        $candidates = collect(range(1, 3))->map(function (int $i) use ($program) {
            $student = User::factory()->create([
                'status' => 'active',
                'program_id' => $program->id,
                'department_id' => $program->department_id,
                'is_proposal_enrolled' => true,
            ]);
            $student->assignRole('student');

            return $student;
        });

        $invitations = $candidates->map(fn ($candidate) => ProjectInvitation::create([
            'project_id' => $project->id,
            'inviter_id' => $leader->id,
            'invitee_id' => $candidate->id,
            'status' => 'pending',
        ]));

        // First candidate rejects...
        Sanctum::actingAs($candidates[0]);
        $this->postJson("/api/proposals/invitations/{$invitations[0]->id}/respond", [
            'response' => 'rejected',
            'comments' => 'Already in another group.',
        ])->assertOk();

        // ...but the other two accepting still reaches the minimum (leader + 2 = 3).
        Sanctum::actingAs($candidates[1]);
        $this->postJson("/api/proposals/invitations/{$invitations[1]->id}/respond", [
            'response' => 'accepted',
        ])->assertOk();

        Sanctum::actingAs($candidates[2]);
        $response = $this->postJson("/api/proposals/invitations/{$invitations[2]->id}/respond", [
            'response' => 'accepted',
        ])->assertOk();

        $this->assertSame('supervisor_pending', $response->json('data.workflow_stage'));

        $replacement = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $replacement->assignRole('student');

        Sanctum::actingAs($leader);
        $inviteResponse = $this->postJson("/api/projects/{$project->id}/invitations", [
            'invitee_ids' => [$replacement->id],
        ])->assertOk();

        $this->assertSame('invitations_pending', $inviteResponse->json('data.workflow_stage'));
        $this->assertTrue(
            ProjectInvitation::query()
                ->where('project_id', $project->id)
                ->where('invitee_id', $replacement->id)
                ->where('status', 'pending')
                ->exists()
        );
    }

    protected function makePendingInvitation(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $invitee = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $invitee->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Team Formation Project',
            'workflow_stage' => 'invitations_pending',
            'status' => 'active',
            'current_phase' => 'proposal',
        ]);

        $invitation = ProjectInvitation::create([
            'project_id' => $project->id,
            'inviter_id' => $leader->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending',
        ]);

        return [$project, $invitation, $leader, $invitee];
    }
}
