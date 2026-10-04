<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectQueryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function createProjectWithTeam(string $programCode = 'CS'): array
    {
        $program = Program::where('code', $programCode)->firstOrFail();
        $programScope = app(ProgramScopeService::class);

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $leader->assignRole('student');
        $programScope->assignUserProgram($leader, $program, true);

        $teammate = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $teammate->assignRole('student');
        $programScope->assignUserProgram($teammate, $program, true);

        $supervisor = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $supervisor->assignRole(['faculty', 'supervisor']);
        $programScope->syncMembership($supervisor, $program->id, [], true);

        $evaluator = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $evaluator->assignRole(['faculty', 'evaluator']);
        $programScope->syncMembership($evaluator, $program->id, [], true);

        $committeeHead = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $committeeHead->assignRole('fyp-committee-head');
        $programScope->syncMembership($committeeHead, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        $committeeMember = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $committeeMember->assignRole('fyp-committee-member');
        $programScope->syncMembership($committeeMember, $program->id, [
            'is_committee_member' => true,
        ], true);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'accepted',
            'title' => 'Query Test Project',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $teammate->id,
            'role' => 'member',
        ]);

        ProjectEvaluator::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'assigned_by' => $committeeHead->id,
        ]);

        return compact('leader', 'teammate', 'supervisor', 'evaluator', 'committeeHead', 'committeeMember', 'project', 'program');
    }

    public function test_student_can_raise_query_for_own_project_without_selecting_project(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createProjectWithTeam();

        Sanctum::actingAs($leader);

        $response = $this->postJson('/api/queries', [
            'subject' => 'Need help with proposal format',
            'message' => 'What font should we use?',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.project_id', $project->id);
        $response->assertJsonPath('data.raised_by_role', 'student');
        $response->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('project_queries', ['project_id' => $project->id, 'raised_by' => $leader->id]);
    }

    public function test_student_query_is_visible_to_all_active_team_members(): void
    {
        ['leader' => $leader, 'teammate' => $teammate] = $this->createProjectWithTeam();

        Sanctum::actingAs($leader);
        $created = $this->postJson('/api/queries', [
            'subject' => 'Team query',
            'message' => 'A question from the leader.',
        ])->json('data');

        Sanctum::actingAs($teammate);
        $this->getJson("/api/queries/{$created['id']}")->assertOk();

        $reply = $this->postJson("/api/queries/{$created['id']}/reply", [
            'message' => 'Adding to this from the teammate.',
        ]);
        $reply->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_student_query_is_not_visible_on_other_students_projects(): void
    {
        ['leader' => $leaderA] = $this->createProjectWithTeam();
        ['leader' => $leaderB] = $this->createProjectWithTeam();

        Sanctum::actingAs($leaderA);
        $created = $this->postJson('/api/queries', [
            'subject' => 'Private to team A',
            'message' => 'Only team A should see this.',
        ])->json('data');

        Sanctum::actingAs($leaderB);
        $this->getJson("/api/queries/{$created['id']}")->assertStatus(422);
    }

    public function test_supervisor_must_select_project_when_raising_query(): void
    {
        ['supervisor' => $supervisor] = $this->createProjectWithTeam();

        Sanctum::actingAs($supervisor);

        $response = $this->postJson('/api/queries', [
            'subject' => 'Which project is this about?',
            'message' => 'Forgot to pick one.',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['project_id']);
    }

    public function test_supervisor_cannot_raise_query_for_project_they_do_not_supervise(): void
    {
        ['supervisor' => $supervisor] = $this->createProjectWithTeam();
        ['project' => $otherProject] = $this->createProjectWithTeam();

        Sanctum::actingAs($supervisor);

        $response = $this->postJson('/api/queries', [
            'project_id' => $otherProject->id,
            'subject' => 'Not my project',
            'message' => 'Should be rejected.',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['project_id']);
    }

    public function test_evaluator_can_raise_query_only_for_assigned_project(): void
    {
        ['evaluator' => $evaluator, 'project' => $project] = $this->createProjectWithTeam();
        ['project' => $otherProject] = $this->createProjectWithTeam();

        Sanctum::actingAs($evaluator);

        $this->postJson('/api/queries', [
            'project_id' => $project->id,
            'subject' => 'Marking criteria',
            'message' => 'Can you clarify the rubric?',
        ])->assertCreated();

        $this->postJson('/api/queries', [
            'project_id' => $otherProject->id,
            'subject' => 'Not assigned here',
            'message' => 'Should fail.',
        ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
    }

    public function test_supervisor_query_is_private_from_student_team(): void
    {
        ['supervisor' => $supervisor, 'project' => $project, 'leader' => $leader] = $this->createProjectWithTeam();

        Sanctum::actingAs($supervisor);
        $created = $this->postJson('/api/queries', [
            'project_id' => $project->id,
            'subject' => 'Supervisor-only question',
            'message' => 'Should not be visible to the team.',
        ])->json('data');

        Sanctum::actingAs($leader);
        $this->getJson("/api/queries/{$created['id']}")->assertStatus(422);
    }

    public function test_committee_member_sees_queries_within_program_scope_only(): void
    {
        ['leader' => $leaderCs, 'committeeMember' => $committeeMemberCs] = $this->createProjectWithTeam('CS');
        ['leader' => $leaderDs] = $this->createProjectWithTeam('DS');

        Sanctum::actingAs($leaderCs);
        $this->postJson('/api/queries', ['subject' => 'CS query', 'message' => 'From CS program.'])->assertCreated();

        Sanctum::actingAs($leaderDs);
        $this->postJson('/api/queries', ['subject' => 'DS query', 'message' => 'From DS program.'])->assertCreated();

        Sanctum::actingAs($committeeMemberCs);
        $response = $this->getJson('/api/queries')->assertOk();

        $subjects = collect($response->json('data.queries'))->pluck('subject')->all();
        $this->assertContains('CS query', $subjects);
        $this->assertNotContains('DS query', $subjects);
    }

    public function test_user_with_respond_permission_but_no_committee_role_can_respond(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createProjectWithTeam();

        $facultyResponder = User::factory()->create(['status' => 'active', 'program_id' => $project->program_id]);
        $facultyResponder->assignRole('faculty');
        $facultyResponder->givePermissionTo('respond to project queries');
        app(ProgramScopeService::class)->syncMembership($facultyResponder, $project->program_id, [], true);

        Sanctum::actingAs($leader);
        $created = $this->postJson('/api/queries', [
            'subject' => 'Needs a permission-based responder',
            'message' => 'Testing permission grant.',
        ])->json('data');

        Sanctum::actingAs($facultyResponder);
        $response = $this->postJson("/api/queries/{$created['id']}/reply", [
            'message' => 'Responding via granted permission.',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'answered');
    }

    public function test_reply_by_responder_marks_answered_and_raiser_reply_reopens(): void
    {
        ['leader' => $leader, 'committeeHead' => $committeeHead] = $this->createProjectWithTeam();

        Sanctum::actingAs($leader);
        $created = $this->postJson('/api/queries', [
            'subject' => 'Status flow test',
            'message' => 'Initial question.',
        ])->json('data');

        Sanctum::actingAs($committeeHead);
        $this->postJson("/api/queries/{$created['id']}/reply", ['message' => 'Here is an answer.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'answered');

        Sanctum::actingAs($leader);
        $this->postJson("/api/queries/{$created['id']}/reply", ['message' => 'Thanks, one more thing.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_close_and_reopen_permission_rules(): void
    {
        ['leader' => $leader, 'committeeHead' => $committeeHead] = $this->createProjectWithTeam();
        ['leader' => $unrelatedLeader] = $this->createProjectWithTeam();

        Sanctum::actingAs($leader);
        $created = $this->postJson('/api/queries', [
            'subject' => 'Close/reopen test',
            'message' => 'Please resolve this.',
        ])->json('data');

        Sanctum::actingAs($unrelatedLeader);
        $this->postJson("/api/queries/{$created['id']}/close")->assertStatus(422);

        Sanctum::actingAs($committeeHead);
        $this->postJson("/api/queries/{$created['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->postJson("/api/queries/{$created['id']}/close")->assertStatus(422);

        // Raiser cannot post a message or reopen a closed query.
        Sanctum::actingAs($leader);
        $this->postJson("/api/queries/{$created['id']}/reply", ['message' => 'Still there?'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
        $this->postJson("/api/queries/{$created['id']}/reopen")->assertStatus(422);

        // Only a responder (admin/committee-head/permission holder) can reopen.
        Sanctum::actingAs($committeeHead);
        $this->postJson("/api/queries/{$created['id']}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_only_responder_can_reopen_closed_query(): void
    {
        ['leader' => $leader, 'committeeHead' => $committeeHead, 'committeeMember' => $committeeMember] = $this->createProjectWithTeam();

        Sanctum::actingAs($leader);
        $created = $this->postJson('/api/queries', [
            'subject' => 'Reopen authority test',
            'message' => 'Please close this once resolved.',
        ])->json('data');

        Sanctum::actingAs($committeeHead);
        $this->postJson("/api/queries/{$created['id']}/close")->assertOk();

        // Unrelated evaluator/supervisor style actor without respond permission cannot reopen either.
        Sanctum::actingAs($leader);
        $this->postJson("/api/queries/{$created['id']}/reopen")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['query']);

        Sanctum::actingAs($committeeMember);
        $this->postJson("/api/queries/{$created['id']}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_committee_head_can_raise_query_directed_at_student_team(): void
    {
        ['committeeHead' => $committeeHead, 'project' => $project, 'leader' => $leader, 'teammate' => $teammate] = $this->createProjectWithTeam();

        Sanctum::actingAs($committeeHead);
        $response = $this->postJson('/api/queries', [
            'project_id' => $project->id,
            'subject' => 'Please clarify your scope',
            'message' => 'Can you clarify the scope of chapter 2?',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.raised_by_role', 'staff');
        $created = $response->json('data');

        Sanctum::actingAs($leader);
        $this->getJson("/api/queries/{$created['id']}")->assertOk();

        Sanctum::actingAs($teammate);
        $reply = $this->postJson("/api/queries/{$created['id']}/reply", [
            'message' => 'Chapter 2 covers the literature review.',
        ]);
        $reply->assertOk()->assertJsonPath('data.status', 'answered');
    }

    public function test_staff_raised_query_is_not_visible_to_unrelated_student(): void
    {
        ['committeeHead' => $committeeHead, 'project' => $project] = $this->createProjectWithTeam();
        ['leader' => $unrelatedLeader] = $this->createProjectWithTeam();

        Sanctum::actingAs($committeeHead);
        $created = $this->postJson('/api/queries', [
            'project_id' => $project->id,
            'subject' => 'Internal follow-up',
            'message' => 'Checking on progress.',
        ])->json('data');

        Sanctum::actingAs($unrelatedLeader);
        $this->getJson("/api/queries/{$created['id']}")->assertStatus(422);
    }

    public function test_supervisor_and_evaluator_cannot_raise_staff_query_without_permission(): void
    {
        ['supervisor' => $supervisor, 'project' => $project] = $this->createProjectWithTeam();

        Sanctum::actingAs($supervisor);
        $response = $this->postJson('/api/queries', [
            'project_id' => $project->id,
            'subject' => 'Should resolve as supervisor, not staff',
            'message' => 'Testing role resolution priority.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.raised_by_role', 'supervisor');
    }

    public function test_pdf_download_permission_matches_view_permission(): void
    {
        ['leader' => $leader, 'committeeHead' => $committeeHead] = $this->createProjectWithTeam();
        ['leader' => $unrelatedLeader] = $this->createProjectWithTeam();

        Sanctum::actingAs($leader);
        $created = $this->postJson('/api/queries', [
            'subject' => 'PDF export test',
            'message' => 'Body of the query.',
        ])->json('data');

        Sanctum::actingAs($committeeHead);
        $this->get("/api/queries/{$created['id']}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        Sanctum::actingAs($unrelatedLeader);
        $this->getJson("/api/queries/{$created['id']}/pdf")->assertStatus(422);
    }
}
