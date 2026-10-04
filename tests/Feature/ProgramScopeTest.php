<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgramScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_program_head_only_sees_projects_in_assigned_program(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();
        $itProgram = Program::where('code', 'IT')->firstOrFail();

        $csHead = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $csHead->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($csHead, $csProgram->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        $csStudent = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $csStudent->assignRole('student');
        $itStudent = User::factory()->create(['status' => 'active', 'program_id' => $itProgram->id]);
        $itStudent->assignRole('student');

        Project::create([
            'student_id' => $csStudent->id,
            'program_id' => $csProgram->id,
            'title' => 'CS Project',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'draft',
            'status' => 'active',
        ]);

        Project::create([
            'student_id' => $itStudent->id,
            'program_id' => $itProgram->id,
            'title' => 'IT Project',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'draft',
            'status' => 'active',
        ]);

        Sanctum::actingAs($csHead);

        $response = $this->getJson('/api/projects?list_context=all');

        $response->assertOk();
        $titles = collect($response->json('data.projects'))->pluck('title');
        $this->assertTrue($titles->contains('CS Project'));
        $this->assertFalse($titles->contains('IT Project'));
    }

    public function test_global_admin_sees_all_program_projects(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();
        $itProgram = Program::where('code', 'IT')->firstOrFail();

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $csStudent = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $itStudent = User::factory()->create(['status' => 'active', 'program_id' => $itProgram->id]);

        Project::create([
            'student_id' => $csStudent->id,
            'program_id' => $csProgram->id,
            'title' => 'CS Project',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'draft',
            'status' => 'active',
        ]);

        Project::create([
            'student_id' => $itStudent->id,
            'program_id' => $itProgram->id,
            'title' => 'IT Project',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'draft',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/projects?list_context=all');
        $titles = collect($response->json('data.projects'))->pluck('title');

        $this->assertTrue($titles->contains('CS Project'));
        $this->assertTrue($titles->contains('IT Project'));
    }

    public function test_program_staff_see_projects_missing_program_id_when_leader_is_in_program(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();

        $csHead = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $csHead->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($csHead, $csProgram->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        $csStudent = User::factory()->create(['status' => 'active', 'program_id' => $csProgram->id]);
        $csStudent->assignRole('student');

        Project::create([
            'student_id' => $csStudent->id,
            'program_id' => null,
            'title' => 'Legacy CS Project',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'committee_review',
            'status' => 'active',
        ]);

        Sanctum::actingAs($csHead);

        $response = $this->getJson('/api/projects?list_context=all');

        $response->assertOk();
        $titles = collect($response->json('data.projects'))->pluck('title');
        $this->assertTrue($titles->contains('Legacy CS Project'));
    }
}
