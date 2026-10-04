<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamMemberDepartmentScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_eligible_members_only_include_same_department_students(): void
    {
        $csProgram = Program::where('code', 'CS')->firstOrFail();
        $dsProgram = Program::where('code', 'DS')->firstOrFail();
        $itProgram = Program::where('code', 'IT')->firstOrFail();

        // CS and DS share the Computer Science department; IT is a different department.
        $leader = $this->makeStudent($csProgram);
        $sameDepartmentStudent = $this->makeStudent($dsProgram, 'Same Dept Student');
        $otherDepartmentStudent = $this->makeStudent($itProgram, 'Other Dept Student');

        Sanctum::actingAs($leader);

        $response = $this->getJson('/api/proposals/eligible-members');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($sameDepartmentStudent->id));
        $this->assertFalse($ids->contains($otherDepartmentStudent->id));
        $this->assertFalse($ids->contains($leader->id));
    }

    protected function makeStudent(Program $program, string $name = 'Student'): User
    {
        $student = User::factory()->create([
            'name' => $name,
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'is_proposal_enrolled' => true,
        ]);

        $student->assignRole('student');

        return $student;
    }
}
