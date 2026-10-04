<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupervisorOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_committee_member_can_view_supervisor_overview(): void
    {
        [$member, $supervisor] = $this->makeStaffAndSupervisor();

        Sanctum::actingAs($member);

        $this->getJson('/api/admin/supervisors')
            ->assertOk()
            ->assertJsonPath('data.0.id', $supervisor->id);
    }

    public function test_committee_member_can_list_projects_for_supervisor(): void
    {
        [$member, $supervisor, $project, $otherProject] = $this->makeSupervisedProjects();

        Sanctum::actingAs($member);

        $response = $this->getJson("/api/projects?list_context=all&supervisor_id={$supervisor->id}");

        $response->assertOk();

        $ids = collect($response->json('data.projects'))->pluck('id')->all();

        $this->assertContains($project->id, $ids);
        $this->assertNotContains($otherProject->id, $ids);
    }

    public function test_committee_member_can_list_projects_for_evaluator(): void
    {
        [$member, $supervisor, $project, $otherProject, $evaluator] = $this->makeSupervisedProjects(withEvaluator: true);

        Sanctum::actingAs($member);

        $response = $this->getJson("/api/projects?list_context=all&evaluator_id={$evaluator->id}");

        $response->assertOk();

        $ids = collect($response->json('data.projects'))->pluck('id')->all();

        $this->assertContains($project->id, $ids);
        $this->assertNotContains($otherProject->id, $ids);
    }

    public function test_committee_member_can_view_supervisor_profile_with_pending_tasks(): void
    {
        [$member, $supervisor, $project, $otherProject] = $this->makeSupervisedProjects();

        Sanctum::actingAs($member);

        $response = $this->getJson("/api/admin/supervisors/{$supervisor->id}")->assertOk();

        $response->assertJsonPath('data.supervisor.id', $supervisor->id);

        $pendingIds = collect($response->json('data.pending_tasks'))->pluck('id')->all();
        $this->assertContains($project->id, $pendingIds, 'Project awaiting supervisor response should be a pending task.');

        $projectIds = collect($response->json('data.projects'))->pluck('id')->all();
        $this->assertContains($project->id, $projectIds);
        $this->assertNotContains($otherProject->id, $projectIds, 'Profile must only list this supervisor\'s own projects.');
    }

    public function test_committee_member_from_other_program_cannot_view_supervisor_profile(): void
    {
        [, $supervisor] = $this->makeSupervisedProjects();

        $dsProgram = Program::where('code', 'DS')->firstOrFail();
        $outsider = User::factory()->create(['status' => 'active', 'program_id' => $dsProgram->id]);
        $outsider->assignRole('fyp-committee-member');
        app(ProgramScopeService::class)->syncMembership($outsider, (int) $dsProgram->id, [], true);

        Sanctum::actingAs($outsider);

        $this->getJson("/api/admin/supervisors/{$supervisor->id}")->assertForbidden();
    }

    public function test_user_without_access_cannot_view_supervisor_overview(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');

        Sanctum::actingAs($supervisor);

        $this->getJson('/api/admin/supervisors')
            ->assertForbidden();
    }

    protected function makeStaffAndSupervisor(): array
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
        app(ProgramScopeService::class)->syncMembership($supervisor, (int) $program->id, [], true);

        return [$member, $supervisor];
    }

    protected function makeSupervisedProjects(bool $withEvaluator = false): array
    {
        [$member, $supervisor] = $this->makeStaffAndSupervisor();
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026-OVERVIEW',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => 'proposal',
        ]);

        $otherSupervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $otherSupervisor->assignRole('supervisor');

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'SP2026',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $otherLeader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'SP2026',
            'is_proposal_enrolled' => true,
        ]);
        $otherLeader->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Target Project',
            'workflow_stage' => 'supervisor_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $otherProject = Project::create([
            'student_id' => $otherLeader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $otherSupervisor->id,
            'title' => 'Other Project',
            'workflow_stage' => 'supervisor_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $evaluator = null;

        if ($withEvaluator) {
            $evaluator = User::factory()->create([
                'status' => 'active',
                'program_id' => $program->id,
                'department_id' => $program->department_id,
            ]);
            $evaluator->assignRole('evaluator');

            ProjectEvaluator::create([
                'project_id' => $project->id,
                'evaluator_id' => $evaluator->id,
                'assigned_by' => $supervisor->id,
            ]);
        }

        return [$member, $supervisor, $project, $otherProject, $evaluator];
    }
}
