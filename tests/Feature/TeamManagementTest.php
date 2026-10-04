<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function makeProjectInPhase(string $phase, ?User $supervisor = null): Project
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $supervisor = $supervisor ?: User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        if (! $supervisor->hasRole('supervisor')) {
            $supervisor->assignRole(['faculty', 'supervisor']);
        }

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id, 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'accepted',
            'title' => "Project {$phase} ".uniqid(),
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => $phase,
            'workflow_stage' => $phase === 'proposal' ? 'committee_review' : 'approved',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $p) {
            ProjectPhase::create(['project_id' => $project->id, 'phase' => $p, 'status' => 'draft']);
        }

        return $project;
    }

    protected function makeUnassignedStudent(Project $likeProject): User
    {
        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $likeProject->program_id,
            'is_proposal_enrolled' => true,
        ]);
        $student->assignRole('student');

        return $student;
    }

    public function test_supervisor_can_directly_add_unassigned_student_during_phase_1(): void
    {
        $project = $this->makeProjectInPhase('phase_1');
        $supervisor = $project->supervisor;
        $newStudent = $this->makeUnassignedStudent($project);

        Sanctum::actingAs($supervisor);

        $response = $this->postJson("/api/projects/{$project->id}/members/direct", [
            'user_ids' => [$newStudent->id],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $newStudent->id,
            'status' => 'active',
        ]);
    }

    public function test_unrelated_supervisor_cannot_directly_add_student(): void
    {
        $project = $this->makeProjectInPhase('phase_1');
        $otherSupervisor = User::factory()->create(['status' => 'active', 'program_id' => $project->program_id]);
        $otherSupervisor->assignRole(['faculty', 'supervisor']);
        $newStudent = $this->makeUnassignedStudent($project);

        Sanctum::actingAs($otherSupervisor);

        $this->postJson("/api/projects/{$project->id}/members/direct", [
            'user_ids' => [$newStudent->id],
        ])->assertStatus(422);
    }

    public function test_admin_can_transfer_student_between_same_phase_projects(): void
    {
        $sourceProject = $this->makeProjectInPhase('phase_1');
        $targetProject = $this->makeProjectInPhase('phase_1');

        $member = $this->makeUnassignedStudent($sourceProject);
        ProjectMember::create(['project_id' => $sourceProject->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/projects/{$targetProject->id}/members/direct", [
            'user_ids' => [$member->id],
        ]);

        $response->assertOk();

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

    public function test_cannot_transfer_student_to_a_different_phase_project(): void
    {
        $sourceProject = $this->makeProjectInPhase('phase_1');
        $targetProject = $this->makeProjectInPhase('phase_2');

        $member = $this->makeUnassignedStudent($sourceProject);
        ProjectMember::create(['project_id' => $sourceProject->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/projects/{$targetProject->id}/members/direct", [
            'user_ids' => [$member->id],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $sourceProject->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
    }

    public function test_supervisor_can_remove_member_during_phase_2(): void
    {
        $project = $this->makeProjectInPhase('phase_2');
        $supervisor = $project->supervisor;
        $member = $this->makeUnassignedStudent($project);
        $projectMember = ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        Sanctum::actingAs($supervisor);

        $this->deleteJson("/api/projects/{$project->id}/members/{$projectMember->id}")->assertOk();

        $this->assertDatabaseHas('project_members', [
            'id' => $projectMember->id,
            'status' => 'removed',
        ]);

        // Removing a member mid-Phase-2 must not corrupt the phase-agnostic workflow_stage.
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'current_phase' => 'phase_2',
            'workflow_stage' => 'approved',
        ]);
    }

    public function test_leader_can_transfer_leadership_to_another_active_member(): void
    {
        $project = $this->makeProjectInPhase('phase_1');
        $leader = $project->student;
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);
        $member = $this->makeUnassignedStudent($project);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        Sanctum::actingAs($leader);

        $response = $this->postJson("/api/projects/{$project->id}/transfer-leadership", [
            'new_leader_id' => $member->id,
        ]);

        $response->assertOk()->assertJsonPath('data.viewer_is_project_leader', false);

        $project->refresh();
        $this->assertSame($member->id, $project->student_id);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'leader',
        ]);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $leader->id,
            'role' => 'member',
        ]);
    }

    public function test_admin_can_transfer_leadership_on_behalf_of_team(): void
    {
        $project = $this->makeProjectInPhase('phase_1');
        $leader = $project->student;
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);
        $member = $this->makeUnassignedStudent($project);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $this->postJson("/api/projects/{$project->id}/transfer-leadership", [
            'new_leader_id' => $member->id,
            'comments' => 'Original leader is on leave.',
        ])->assertOk();

        $this->assertSame($member->id, $project->fresh()->student_id);
    }

    public function test_unrelated_student_cannot_transfer_leadership(): void
    {
        $project = $this->makeProjectInPhase('phase_1');
        $member = $this->makeUnassignedStudent($project);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member', 'status' => 'active']);

        $outsider = User::factory()->create(['status' => 'active']);
        $outsider->assignRole('student');

        Sanctum::actingAs($outsider);

        $this->postJson("/api/projects/{$project->id}/transfer-leadership", [
            'new_leader_id' => $member->id,
        ])->assertStatus(422);

        $this->assertSame($project->student_id, $project->fresh()->student_id);
    }

    public function test_cannot_transfer_leadership_to_a_non_member(): void
    {
        $project = $this->makeProjectInPhase('phase_1');
        $leader = $project->student;
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);
        $outsider = $this->makeUnassignedStudent($project);

        Sanctum::actingAs($leader);

        $this->postJson("/api/projects/{$project->id}/transfer-leadership", [
            'new_leader_id' => $outsider->id,
        ])->assertStatus(422);

        $this->assertSame($leader->id, $project->fresh()->student_id);
    }
}
