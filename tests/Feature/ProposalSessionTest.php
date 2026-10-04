<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use App\Services\ProposalSessionService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProposalSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_student_cannot_register_after_initial_deadline(): void
    {
        [$student] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subHour(),
            'final_lock_deadline' => now()->addWeek(),
            'is_submission_open' => true,
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk();
        $this->assertFalse($response->json('data.can_register_proposal'));
        $this->assertSame('initial_deadline_passed', $response->json('data.registration.block_reason'));
    }

    public function test_existing_project_leader_can_edit_after_initial_deadline_until_final_lock(): void
    {
        [$student, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subHour(),
            'final_lock_deadline' => now()->addWeek(),
            'is_submission_open' => false,
        ]);

        $project = Project::create([
            'student_id' => $student->id,
            'program_id' => $student->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Existing Team',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $this->postJson("/api/projects/{$project->id}/invitations", [
            'invitee_ids' => [$this->makeEligibleStudent($student)->id],
        ])->assertOk();
    }

    public function test_invitee_can_accept_invitation_after_initial_deadline_until_final_lock(): void
    {
        [$leader, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subHour(),
            'final_lock_deadline' => now()->addWeek(),
            'is_submission_open' => false,
        ]);

        $invitee = $this->makeEligibleStudent($leader);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $leader->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Existing Team',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $invitation = ProjectInvitation::create([
            'project_id' => $project->id,
            'inviter_id' => $leader->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($invitee);

        $this->postJson("/api/proposals/invitations/{$invitation->id}/respond", [
            'response' => 'accepted',
        ])->assertOk();

        $this->assertSame('accepted', $invitation->fresh()->status);
    }

    public function test_project_edits_blocked_after_final_lock(): void
    {
        [$student, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subDays(2),
            'final_lock_deadline' => now()->subHour(),
            'is_submission_open' => false,
            'is_fully_locked' => true,
        ]);

        $project = Project::create([
            'student_id' => $student->id,
            'program_id' => $student->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Locked Team',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $this->postJson("/api/projects/{$project->id}/invitations", [
            'invitee_ids' => [$this->makeEligibleStudent($student)->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['session']);
    }

    public function test_committee_head_can_create_and_open_session(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $create = $this->postJson('/api/proposal-sessions', [
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'initial_draft_deadline' => now()->addWeek()->toDateTimeString(),
            'final_lock_deadline' => now()->addWeeks(2)->toDateTimeString(),
        ]);

        $create->assertCreated();
        $sessionId = $create->json('data.id');

        $this->postJson("/api/proposal-sessions/{$sessionId}/open-submissions")->assertOk();
    }

    public function test_opening_submissions_is_rejected_once_initial_deadline_has_passed(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2027',
            'code' => 'FA27',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'is_submission_open' => false,
            'initial_draft_deadline' => now()->subHour(),
            'final_lock_deadline' => now()->addWeek(),
        ]);

        Sanctum::actingAs($head);

        $this->postJson("/api/proposal-sessions/{$session->id}/open-submissions")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session']);

        $this->assertFalse($session->fresh()->is_submission_open);

        // Extending the deadline into the future unblocks the open action.
        $this->putJson("/api/proposal-sessions/{$session->id}", [
            'name' => $session->name,
            'code' => $session->code,
            'initial_draft_deadline' => now()->addDay()->toDateTimeString(),
            'final_lock_deadline' => now()->addWeek()->toDateTimeString(),
        ])->assertOk();

        $this->postJson("/api/proposal-sessions/{$session->id}/open-submissions")->assertOk();
        $this->assertTrue($session->fresh()->is_submission_open);
    }

    public function test_extension_only_applies_to_current_proposal_session(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $current = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $archived = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Spring 2026',
            'code' => 'SP26',
            'status' => 'archived',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $this->postJson("/api/proposal-sessions/{$archived->id}/extend-deadlines", [
            'initial_draft_deadline' => now()->addDays(3)->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['session']);

        $this->postJson("/api/proposal-sessions/{$current->id}/extend-deadlines", [
            'initial_draft_deadline' => now()->addDays(3)->toDateTimeString(),
        ])->assertOk();
    }

    public function test_individual_student_extension_allows_late_registration(): void
    {
        [$student, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subDay(),
            'final_lock_deadline' => now()->addWeek(),
            'is_submission_open' => true,
        ]);

        $program = Program::where('code', 'CS')->firstOrFail();
        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($student);
        $this->getJson('/api/proposals/my-project')
            ->assertOk()
            ->assertJsonPath('data.can_register_proposal', false);

        Sanctum::actingAs($head);

        $studentsRes = $this->getJson("/api/proposal-sessions/{$session->id}/students");
        $studentsRes->assertOk();
        $this->assertTrue(collect($studentsRes->json('data.students'))->pluck('id')->contains($student->id));

        $extendedInitial = now()->addDays(2)->toDateTimeString();

        $grant = $this->postJson("/api/proposal-sessions/{$session->id}/extensions", [
            'user_id' => $student->id,
            'extended_initial_deadline' => $extendedInitial,
            'extended_final_deadline' => now()->addWeeks(2)->toDateTimeString(),
            'reason' => 'Medical leave',
        ]);

        $grant->assertOk();
        $grant->assertJsonPath('data.extension.reason', 'Medical leave');

        $show = $this->getJson("/api/proposal-sessions/{$session->id}");
        $show->assertOk()
            ->assertJsonPath('data.session.id', $session->id)
            ->assertJsonPath('data.extensions.0.reason', 'Medical leave');

        $this->assertDatabaseHas('proposal_session_extensions', [
            'proposal_session_id' => $session->id,
            'user_id' => $student->id,
            'reason' => 'Medical leave',
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/proposals/my-project')
            ->assertOk()
            ->assertJsonPath('data.can_register_proposal', true)
            ->assertJsonPath('data.registration.session.phase', 'initial_draft');
    }

    public function test_extension_granted_to_one_team_member_applies_to_whole_project(): void
    {
        [$leader, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subDay(),
            'final_lock_deadline' => now()->addWeek(),
            'is_submission_open' => true,
        ]);

        $teammate = User::factory()->create([
            'status' => 'active',
            'program_id' => $leader->program_id,
            'department_id' => $leader->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $teammate->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $leader->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'accepted',
            'title' => 'Team Extension Project',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'group_confirmed',
            'status' => 'active',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $teammate->id,
            'role' => 'member',
        ]);

        $program = Program::where('code', 'CS')->firstOrFail();
        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        // Grant the extension to only the teammate (not the leader).
        $grant = $this->postJson("/api/proposal-sessions/{$session->id}/extensions", [
            'user_id' => $teammate->id,
            'extended_initial_deadline' => now()->addDays(2)->toDateTimeString(),
            'extended_final_deadline' => now()->addWeeks(2)->toDateTimeString(),
            'reason' => 'Team-wide illness delay',
        ]);

        $grant->assertOk();
        $grant->assertJsonPath('data.extension.team_size', 2);

        $this->assertDatabaseHas('proposal_session_extensions', [
            'proposal_session_id' => $session->id,
            'user_id' => $leader->id,
            'reason' => 'Team-wide illness delay',
        ]);
        $this->assertDatabaseHas('proposal_session_extensions', [
            'proposal_session_id' => $session->id,
            'user_id' => $teammate->id,
            'reason' => 'Team-wide illness delay',
        ]);

        $show = $this->getJson("/api/proposal-sessions/{$session->id}")->assertOk();
        $groups = $show->json('data.extensions');
        $this->assertCount(1, $groups, 'Both team members should collapse into a single grouped row.');
        $this->assertEquals($project->id, $groups[0]['project_id']);
        $studentIds = collect($groups[0]['students'])->pluck('id')->all();
        $this->assertContains($leader->id, $studentIds);
        $this->assertContains($teammate->id, $studentIds);

        // Revoking via either team member removes the extension for the whole team.
        $this->deleteJson("/api/proposal-sessions/{$session->id}/extensions/{$leader->id}")->assertOk();

        $this->assertDatabaseMissing('proposal_session_extensions', [
            'proposal_session_id' => $session->id,
            'user_id' => $leader->id,
        ]);
        $this->assertDatabaseMissing('proposal_session_extensions', [
            'proposal_session_id' => $session->id,
            'user_id' => $teammate->id,
        ]);
    }

    public function test_individual_extension_rejects_student_not_in_session(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'is_submission_open' => true,
        ]);

        $wrongSessionStudent = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'SP26',
            'is_proposal_enrolled' => true,
        ]);
        $wrongSessionStudent->assignRole('student');

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $this->postJson("/api/proposal-sessions/{$session->id}/extensions", [
            'user_id' => $wrongSessionStudent->id,
            'extended_initial_deadline' => now()->addDays(2)->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }

    public function test_cannot_manually_close_submissions_while_initial_deadline_pending(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->addHours(2),
            'final_lock_deadline' => now()->addWeek(),
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $this->postJson("/api/proposal-sessions/{$session->id}/close-submissions")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['session']);

        $this->assertTrue($session->fresh()->is_submission_open);
    }

    public function test_submissions_auto_close_when_initial_deadline_passes(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->subMinute(),
            'final_lock_deadline' => now()->addWeek(),
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $this->getJson('/api/proposal-sessions')
            ->assertOk();

        $this->assertFalse($session->fresh()->is_submission_open);
    }

    public function test_sync_command_closes_expired_proposal_sessions(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Spring 2026',
            'code' => 'SP26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->subMinute(),
            'final_lock_deadline' => now()->addWeek(),
        ]);

        Artisan::call('proposal-sessions:sync-submissions');

        $this->assertFalse($session->fresh()->is_submission_open);
    }

    public function test_bulk_sync_closes_sessions_without_loading_admin_page(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Spring 2026',
            'code' => 'SP26B',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->subMinute(),
            'final_lock_deadline' => now()->addWeek(),
        ]);

        $closed = app(ProposalSessionService::class)->syncAllExpiredSubmissionStates();

        $this->assertSame(1, $closed);
        $this->assertFalse($session->fresh()->is_submission_open);
    }

    public function test_extension_allows_submit_when_session_submissions_closed(): void
    {
        [$student, $session] = array_slice($this->makeStudentWithSession([
            'initial_draft_deadline' => now()->subDay(),
            'final_lock_deadline' => now()->addWeek(),
            'is_submission_open' => false,
        ]), 0, 2);

        $program = Program::where('code', 'CS')->firstOrFail();
        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($student);
        $this->getJson('/api/proposals/my-project')
            ->assertOk()
            ->assertJsonPath('data.can_register_proposal', false);

        Sanctum::actingAs($head);
        $this->postJson("/api/proposal-sessions/{$session->id}/extensions", [
            'user_id' => $student->id,
            'extended_initial_deadline' => now()->addDays(2)->toDateTimeString(),
        ])->assertOk();

        Sanctum::actingAs($student);
        $this->getJson('/api/proposals/my-project')
            ->assertOk()
            ->assertJsonPath('data.can_register_proposal', true);
    }

    public function test_my_project_includes_countdown_for_project_leader(): void
    {
        [$student, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->addDays(3),
            'final_lock_deadline' => now()->addWeeks(2),
            'is_submission_open' => true,
        ]);

        Project::create([
            'student_id' => $student->id,
            'program_id' => $student->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Leader Project',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk();
        $this->assertSame($session->id, $response->json('data.registration.session.session.id'));
        $this->assertSame('initial_draft', $response->json('data.registration.session.phase'));
        $this->assertNotNull($response->json('data.registration.session.countdown_target'));
    }

    public function test_my_project_uses_project_session_when_user_session_code_mismatches(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'SP26',
            'is_proposal_enrolled' => true,
        ]);
        $student->assignRole('student');

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->addDays(4),
            'final_lock_deadline' => now()->addWeeks(2),
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $supervisor = $this->makeSupervisor($student);

        Project::create([
            'student_id' => $student->id,
            'program_id' => $student->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Existing Proposal',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk();
        $this->assertSame($session->id, $response->json('data.registration.session.session.id'));
        $this->assertSame('initial_draft', $response->json('data.registration.session.phase'));
        $this->assertNotNull($response->json('data.registration.session.countdown_target'));
    }

    public function test_my_project_includes_countdown_for_team_member(): void
    {
        [$leader, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->addDays(2),
            'final_lock_deadline' => now()->addWeeks(2),
            'is_submission_open' => true,
        ]);

        $member = $this->makeEligibleStudent($leader);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $leader->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Team Project',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $project->members()->create([
            'user_id' => $member->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($member);

        $response = $this->getJson('/api/proposals/my-project');

        $response->assertOk();
        $this->assertSame($session->id, $response->json('data.registration.session.session.id'));
        $this->assertSame('initial_draft', $response->json('data.registration.session.phase'));
        $this->assertTrue($response->json('data.registration.session.can_edit_project'));
        $this->assertNotNull($response->json('data.registration.session.countdown_target'));
    }

    public function test_countdown_shows_final_lock_after_manual_submission_close_before_initial_deadline(): void
    {
        [$student, $session, $supervisor] = $this->makeStudentWithSession([
            'initial_draft_deadline' => now()->addDays(5),
            'final_lock_deadline' => now()->addWeeks(2),
            'is_submission_open' => false,
        ]);

        Project::create([
            'student_id' => $student->id,
            'program_id' => $student->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Existing Team',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/proposals/my-project')->assertOk();

        $this->assertSame('final_edits', $response->json('data.registration.session.phase'));
        $this->assertSame(
            $session->final_lock_deadline->toDateTimeString(),
            $response->json('data.registration.session.countdown_target')
        );
        $this->assertStringContainsString('Final lock deadline', $response->json('data.registration.session.countdown_label'));
    }

    public function test_phase_one_countdown_uses_phase_one_final_lock_after_submission_close(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
            'is_submission_open' => false,
            'phase_1_initial_deadline' => now()->addDays(4),
            'phase_1_final_lock_deadline' => now()->addWeeks(2),
        ]);

        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $student->assignRole('student');

        $supervisor = $this->makeSupervisor($student);

        Project::create([
            'student_id' => $student->id,
            'program_id' => $student->program_id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Phase 1 Project',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/proposals/my-project')->assertOk();

        $this->assertSame('final_edits', $response->json('data.registration.session.phase'));
        $this->assertSame(
            $session->phase_1_final_lock_deadline->toDateTimeString(),
            $response->json('data.registration.session.countdown_target')
        );
        $this->assertStringContainsString('Phase 1 final lock deadline', $response->json('data.registration.session.countdown_label'));
    }

    public function test_student_manager_can_list_proposal_session_options(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Spring 2026',
            'code' => 'SP26',
            'status' => 'archived',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/proposal-sessions/options', [
            'program_id' => $program->id,
        ]);

        $response->assertOk();
        $codes = collect($response->json('data.sessions'))->pluck('code')->all();
        $this->assertCount(1, $codes);
        $this->assertContains('FA26', $codes);

        $all = $this->getJson('/api/proposal-sessions/options?include_all=1&program_id='.$program->id);
        $all->assertOk();
        $allCodes = collect($all->json('data.sessions'))->pluck('code')->all();
        $this->assertCount(2, $allCodes);
        $this->assertContains('SP26', $allCodes);
    }

    public function test_complete_proposal_phase_keeps_pending_projects_and_unplaced_students(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $approvedLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $approvedLeader->assignRole('student');

        $pendingLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $pendingLeader->assignRole('student');

        $unplacedStudent = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $unplacedStudent->assignRole('student');

        $approvedMember = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $approvedMember->assignRole('student');

        $supervisor = $this->makeSupervisor($approvedLeader);

        $approvedProject = Project::create([
            'student_id' => $approvedLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Approved Team',
            'workflow_stage' => 'approved',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $approvedProject->members()->create([
            'user_id' => $approvedMember->id,
            'status' => 'active',
        ]);

        $pendingProject = Project::create([
            'student_id' => $pendingLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Pending Team',
            'workflow_stage' => 'committee_final',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $preview = $this->getJson("/api/proposal-sessions/{$session->id}/complete-proposal-phase/preview");
        $preview->assertOk();
        $preview->assertJsonPath('data.approved_count', 1);
        $preview->assertJsonPath('data.pending_projects_count', 1);
        $preview->assertJsonPath('data.unplaced_students_count', 2);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/complete-proposal-phase");

        $response->assertOk();
        $response->assertJsonPath('data.summary.approved_projects_kept', 1);
        $response->assertJsonPath('data.summary.pending_projects_kept', 1);
        $response->assertJsonPath('data.summary.unplaced_students_kept', 2);
        $response->assertJsonPath('data.summary.projects_prepared_for_phase_1', 1);
        $this->assertSame('phase_1', $response->json('data.session.lifecycle_phase'));
        $this->assertTrue($response->json('data.session.is_submission_open'));
        $this->assertFalse($response->json('data.session.is_fully_locked'));

        $this->assertDatabaseHas('projects', [
            'id' => $approvedProject->id,
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
        ]);
        // Nothing is deleted anymore — the pending project and unplaced student remain intact.
        $this->assertDatabaseHas('projects', ['id' => $pendingProject->id]);
        $this->assertDatabaseHas('users', ['id' => $approvedLeader->id]);
        $this->assertDatabaseHas('users', ['id' => $approvedMember->id]);
        $this->assertDatabaseHas('users', ['id' => $pendingLeader->id]);
        $this->assertDatabaseHas('users', ['id' => $unplacedStudent->id]);
    }

    public function test_pending_proposal_project_carries_forward_into_next_session(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $oldSession = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Spring 2026',
            'code' => 'SP26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $pendingLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $pendingLeader->assignRole('student');

        $supervisor = $this->makeSupervisor($pendingLeader);

        $pendingProject = Project::create([
            'student_id' => $pendingLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $oldSession->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Carried Team',
            'workflow_stage' => 'committee_review',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/proposal-sessions', [
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26-'.uniqid(),
        ])->assertCreated();

        $newSession = ProposalSession::where('program_id', $program->id)
            ->where('id', '!=', $oldSession->id)
            ->firstOrFail();

        $this->assertDatabaseHas('projects', [
            'id' => $pendingProject->id,
            'proposal_session_id' => $newSession->id,
            'workflow_stage' => 'committee_review',
        ]);
    }

    public function test_complete_phase1_advances_approved_projects_and_keeps_pending(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
            'is_submission_open' => true,
        ]);

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $supervisor = $this->makeSupervisor($leader);

        $approvedProject = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Approved Phase 1',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        $approvedProject->phases()->create([
            'phase' => 'phase_1',
            'status' => 'approved',
        ]);

        $pendingLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $pendingLeader->assignRole('student');

        $pendingProject = Project::create([
            'student_id' => $pendingLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Pending Phase 1',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        $pendingProject->phases()->create([
            'phase' => 'phase_1',
            'status' => 'submitted',
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/complete-phase-1");

        $response->assertOk();
        $response->assertJsonPath('data.summary.phase_1_approved_advanced', 1);
        $response->assertJsonPath('data.summary.phase_1_pending_kept', 1);
        $this->assertSame('phase_2', $response->json('data.session.lifecycle_phase'));

        $this->assertDatabaseHas('projects', [
            'id' => $approvedProject->id,
            'current_phase' => 'phase_2',
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $pendingProject->id,
            'current_phase' => 'phase_1',
        ]);
    }

    public function test_stray_phase2_project_carries_forward_when_phase1_completes(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $oldSession = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Spring 2026',
            'code' => 'SP26',
            'status' => 'archived',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_2,
        ]);

        $strayLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);
        $strayLeader->assignRole('student');

        $supervisor = $this->makeSupervisor($strayLeader);

        $strayProject = Project::create([
            'student_id' => $strayLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $oldSession->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Stray Phase 2',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_2',
            'status' => 'active',
        ]);
        $strayProject->phases()->create(['phase' => 'phase_2', 'status' => 'submitted']);

        $activeSession = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
            'is_submission_open' => true,
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/proposal-sessions/{$activeSession->id}/complete-phase-1");

        $response->assertOk();
        $response->assertJsonPath('data.summary.phase_2_repeats_carried_forward', 1);

        $this->assertDatabaseHas('projects', [
            'id' => $strayProject->id,
            'proposal_session_id' => $activeSession->id,
            'current_phase' => 'phase_2',
        ]);
    }

    public function test_complete_phase2_marks_approved_projects_completed_and_keeps_pending(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_2,
            'is_submission_open' => true,
        ]);

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $supervisor = $this->makeSupervisor($leader);

        $approvedProject = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Approved Phase 2',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_2',
            'status' => 'active',
        ]);

        $approvedProject->phases()->create([
            'phase' => 'phase_2',
            'status' => 'approved',
        ]);

        $pendingLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $pendingLeader->assignRole('student');

        $pendingProject = Project::create([
            'student_id' => $pendingLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Pending Phase 2',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_2',
            'status' => 'active',
        ]);

        $pendingProject->phases()->create([
            'phase' => 'phase_2',
            'status' => 'revision_required',
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/complete-phase-2");

        $response->assertOk();
        $response->assertJsonPath('data.summary.phase_2_approved_completed', 1);
        $response->assertJsonPath('data.summary.phase_2_pending_kept', 1);
        $this->assertSame('archived', $response->json('data.session.status'));

        $this->assertDatabaseHas('projects', [
            'id' => $approvedProject->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $pendingProject->id,
            'status' => 'active',
            'current_phase' => 'phase_2',
        ]);
    }

    protected function makeStudentWithSession(array $sessionOverrides = []): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $student->assignRole('student');

        $session = ProposalSession::create(array_merge([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->addWeek(),
            'final_lock_deadline' => now()->addWeeks(2),
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ], $sessionOverrides));

        $supervisor = $this->makeSupervisor($student);

        return [$student, $session, $supervisor];
    }

    protected function makeSupervisor(User $student): User
    {
        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $student->program_id,
            'department_id' => $student->department_id,
        ]);
        $supervisor->assignRole('supervisor');
        app(ProgramScopeService::class)->syncMembership($supervisor, (int) $student->program_id, [], true);

        return $supervisor;
    }

    protected function makeEligibleStudent(User $leader): User
    {
        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $leader->program_id,
            'department_id' => $leader->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $student->assignRole('student');

        return $student;
    }
}
