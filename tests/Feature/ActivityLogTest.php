<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function projectInProgram(string $programCode): Project
    {
        $program = Program::where('code', $programCode)->firstOrFail();
        $leader = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $leader->assignRole('student');
        app(ProgramScopeService::class)->assignUserProgram($leader, $program, true);

        return Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'title' => 'Log Test Project '.$programCode,
            'description' => 'Test.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);
    }

    public function test_user_without_permission_cannot_view_activity_logs(): void
    {
        $faculty = User::factory()->create(['status' => 'active']);
        $faculty->assignRole('faculty');

        Sanctum::actingAs($faculty);

        $this->getJson('/api/admin/activity-logs')->assertStatus(403);
    }

    public function test_admin_sees_logs_across_all_programs(): void
    {
        $csProject = $this->projectInProgram('CS');
        $dsProject = $this->projectInProgram('DS');

        ActivityLogService::log('create', 'projects', 'CS project activity', null, $csProject->id);
        ActivityLogService::log('create', 'projects', 'DS project activity', null, $dsProject->id);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/activity-logs')->assertOk();

        $descriptions = collect($response->json('data.logs'))->pluck('description')->all();
        $this->assertContains('CS project activity', $descriptions);
        $this->assertContains('DS project activity', $descriptions);
    }

    public function test_committee_head_only_sees_logs_within_their_program(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();
        $csProject = $this->projectInProgram('CS');
        $dsProject = $this->projectInProgram('DS');

        ActivityLogService::log('create', 'projects', 'CS project activity', null, $csProject->id);
        ActivityLogService::log('create', 'projects', 'DS project activity', null, $dsProject->id);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $csProgram->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);
        $response = $this->getJson('/api/admin/activity-logs')->assertOk();

        $descriptions = collect($response->json('data.logs'))->pluck('description')->all();
        $this->assertContains('CS project activity', $descriptions);
        $this->assertNotContains('DS project activity', $descriptions);
    }

    public function test_faculty_with_direct_permission_can_view_scoped_logs(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();
        $csProject = $this->projectInProgram('CS');

        ActivityLogService::log('create', 'projects', 'CS project activity', null, $csProject->id);

        $faculty = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $faculty->assignRole('faculty');
        $faculty->givePermissionTo('view activity logs');
        app(ProgramScopeService::class)->syncMembership($faculty, $csProgram->id, [], true);

        Sanctum::actingAs($faculty);
        $response = $this->getJson('/api/admin/activity-logs')->assertOk();

        $descriptions = collect($response->json('data.logs'))->pluck('description')->all();
        $this->assertContains('CS project activity', $descriptions);
    }

    public function test_project_query_actions_are_logged(): void
    {
        $project = $this->projectInProgram('CS');
        $leader = $project->student;

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($leader);
        $this->postJson('/api/queries', [
            'subject' => 'Audit trail check',
            'message' => 'Does this get logged?',
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/admin/activity-logs?module=project_queries')->assertOk();

        $descriptions = collect($response->json('data.logs'))->pluck('description')->all();
        $this->assertTrue(collect($descriptions)->contains(fn ($d) => str_contains($d, 'Audit trail check')));
    }
}
