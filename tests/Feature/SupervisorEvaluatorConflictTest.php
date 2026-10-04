<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\Program;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupervisorEvaluatorConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function createProjectWithProgramScope(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $programScope = app(ProgramScopeService::class);

        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $leader->assignRole('student');
        $programScope->assignUserProgram($leader, $program, true);

        $faculty = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $faculty->assignRole(['faculty', 'supervisor', 'evaluator']);
        $programScope->syncMembership($faculty, $program->id, [], true);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        $programScope->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        $otherEvaluator = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $otherEvaluator->assignRole(['faculty', 'evaluator']);
        $programScope->syncMembership($otherEvaluator, $program->id, [], true);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'supervisor_id' => $faculty->id,
            'supervisor_status' => 'accepted',
            'title' => 'Conflict Test Project',
            'description' => 'Test project.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        return compact('leader', 'faculty', 'head', 'otherEvaluator', 'project');
    }

    public function test_cannot_assign_project_supervisor_as_evaluator(): void
    {
        ['faculty' => $faculty, 'head' => $head, 'otherEvaluator' => $otherEvaluator, 'project' => $project] = $this->createProjectWithProgramScope();

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $project->student_id,
            'role' => 'leader',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create([
                'project_id' => $project->id,
                'phase' => $phase,
                'status' => 'draft',
            ]);
        }

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/projects/{$project->id}/evaluators", [
            'evaluator_ids' => [$faculty->id, $otherEvaluator->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['evaluator_ids']);
    }

    public function test_evaluator_list_excludes_project_supervisor(): void
    {
        ['faculty' => $faculty, 'head' => $head, 'project' => $project] = $this->createProjectWithProgramScope();
        $faculty->update(['name' => 'Shared Faculty']);

        Sanctum::actingAs($head);

        $response = $this->getJson("/api/proposals/evaluators?project_id={$project->id}");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($faculty->id, $ids);
    }
}
