<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalSession;
use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SessionStudentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    public function test_admin_can_import_students_when_session_is_open(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $session = $this->makeOpenSession();

        Sanctum::actingAs($admin);

        $file = $this->makeStudentSpreadsheet([
            ['SAP ID', 'Name', 'Email', 'Password'],
            ['SP26-101', 'Imported Student', 'imported.student@fyp.com', 'Student@123'],
        ]);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/students/import", [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.created.0.sap_id', 'SP26-101')
            ->assertJsonPath('data.created.0.email', 'imported.student@fyp.com');

        $student = User::where('email', 'imported.student@fyp.com')->first();

        $this->assertNotNull($student);
        $this->assertSame('SP26-101', $student->registration_no);
        $this->assertSame('FA26', $student->session);
        $this->assertTrue($student->is_proposal_enrolled);
        $this->assertTrue($student->hasRole('student'));
        $this->assertTrue(password_verify('Student@123', $student->password));
    }

    public function test_import_is_blocked_when_session_is_not_open(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $session = $this->makeOpenSession(['is_submission_open' => false]);

        Sanctum::actingAs($admin);

        $file = $this->makeStudentSpreadsheet([
            ['SAP ID', 'Name', 'Email', 'Password'],
            ['SP26-102', 'Blocked Student', 'blocked.student@fyp.com', 'Student@123'],
        ]);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/students/import", [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.session.0', 'Open the session before uploading the student list.');

        $this->assertNull(User::where('email', 'blocked.student@fyp.com')->first());
    }

    public function test_import_updates_existing_student_password_and_session(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeOpenSession();

        $existing = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'email' => 'existing.student@fyp.com',
            'registration_no' => 'SP26-200',
            'session' => 'OLD',
            'is_proposal_enrolled' => false,
        ]);
        $existing->assignRole('student');

        Sanctum::actingAs($admin);

        $file = $this->makeStudentSpreadsheet([
            ['SAP ID', 'Name', 'Email', 'Password'],
            ['SP26-200', 'Updated Student', 'existing.student@fyp.com', 'NewPass@123'],
        ]);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/students/import", [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 1);

        $existing->refresh();

        $this->assertSame('Updated Student', $existing->name);
        $this->assertSame('FA26', $existing->session);
        $this->assertTrue($existing->is_proposal_enrolled);
        $this->assertTrue(password_verify('NewPass@123', $existing->password));
    }

    public function test_import_skips_student_who_already_cleared_proposal_phase(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeOpenSession();

        $existing = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'email' => 'cleared.student@fyp.com',
            'registration_no' => 'SP26-300',
            'session' => 'OLD',
        ]);
        $existing->assignRole('student');

        Project::create([
            'student_id' => $existing->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'title' => 'Already Cleared Project',
            'workflow_stage' => 'draft',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        // Deliberately no password column — a re-listed, already-cleared
        // student's row shouldn't need one, since nothing should be written.
        $file = $this->makeStudentSpreadsheet([
            ['SAP ID', 'Name', 'Email', 'Password'],
            ['SP26-300', 'Should Not Change', 'cleared.student@fyp.com', ''],
        ]);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/students/import", [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.updated_count', 0)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.error_count', 0)
            ->assertJsonPath('data.skipped.0.email', 'cleared.student@fyp.com');

        $existing->refresh();

        $this->assertSame('OLD', $existing->session);
        $this->assertNotSame('Should Not Change', $existing->name);
    }

    public function test_import_still_updates_student_who_has_not_cleared_proposal_phase(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $program = Program::where('code', 'CS')->firstOrFail();
        $session = $this->makeOpenSession();

        $existing = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'email' => 'pending.student@fyp.com',
            'registration_no' => 'SP26-301',
            'session' => 'OLD',
        ]);
        $existing->assignRole('student');

        Project::create([
            'student_id' => $existing->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'title' => 'Still In Proposal',
            'workflow_stage' => 'invitations_pending',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $file = $this->makeStudentSpreadsheet([
            ['SAP ID', 'Name', 'Email', 'Password'],
            ['SP26-301', 'Updated While Pending', 'pending.student@fyp.com', 'NewPass@123'],
        ]);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/students/import", [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.skipped_count', 0);

        $existing->refresh();

        $this->assertSame('Updated While Pending', $existing->name);
        $this->assertSame('FA26', $existing->session);
    }

    public function test_admin_can_download_student_import_template(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $session = $this->makeOpenSession();

        Sanctum::actingAs($admin);

        $response = $this->get("/api/proposal-sessions/{$session->id}/students/import/template");

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );
    }

    protected function makeOpenSession(array $overrides = []): ProposalSession
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        return ProposalSession::create(array_merge([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'is_submission_open' => true,
            'initial_draft_deadline' => now()->addWeek(),
            'final_lock_deadline' => now()->addWeeks(2),
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ], $overrides));
    }

    protected function makeStudentSpreadsheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'session-student-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'session-students-import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
