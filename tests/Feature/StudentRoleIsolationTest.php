<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_cannot_create_user_with_student_and_admin_roles(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/users', [
            'name' => 'Sneaky Student',
            'email' => 'sneaky-student@example.com',
            'password' => 'Password123!',
            'program_id' => $program->id,
            'roles' => ['student', 'admin'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['roles']);
        $this->assertDatabaseMissing('users', ['email' => 'sneaky-student@example.com']);
    }

    public function test_cannot_grant_direct_permission_to_student_on_create(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/users', [
            'name' => 'Sneaky Student 2',
            'email' => 'sneaky-student-2@example.com',
            'password' => 'Password123!',
            'program_id' => $program->id,
            'roles' => ['student'],
            'permissions' => ['assign evaluators'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['permissions']);
    }

    public function test_cannot_add_committee_role_to_existing_student_via_update(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $student = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $student->assignRole('student');

        Sanctum::actingAs($this->admin());

        $response = $this->putJson("/api/users/{$student->id}", [
            'name' => $student->name,
            'email' => $student->email,
            'program_id' => $program->id,
            'roles' => ['student', 'fyp-committee-head'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['roles']);
        $this->assertFalse($student->fresh()->hasRole('fyp-committee-head'));
    }

    public function test_cannot_grant_direct_permission_to_existing_student_without_resubmitting_roles(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();
        $student = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $student->assignRole('student');

        Sanctum::actingAs($this->admin());

        $response = $this->putJson("/api/users/{$student->id}", [
            'name' => $student->name,
            'email' => $student->email,
            'program_id' => $program->id,
            'permissions' => ['manage proposal sessions'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['permissions']);
        $this->assertFalse($student->fresh()->can('manage proposal sessions'));
    }

    public function test_staff_role_combinations_still_work(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        Sanctum::actingAs($this->admin());

        $response = $this->postJson('/api/users', [
            'name' => 'Faculty Evaluator',
            'email' => 'faculty-evaluator@example.com',
            'password' => 'Password123!',
            'program_id' => $program->id,
            'roles' => ['faculty', 'supervisor', 'evaluator'],
        ]);

        $response->assertCreated();
    }
}
