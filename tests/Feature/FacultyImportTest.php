<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use App\Services\FacultyImportService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FacultyImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
    }

    protected function defaultProgramId(): int
    {
        return (int) Program::query()->value('id');
    }

    public function test_admin_can_import_faculty_from_excel(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $file = $this->makeFacultySpreadsheet([
            ['SAP ID', 'Name', 'Email'],
            ['FAC-101', 'Dr. Imported Faculty', 'imported.faculty@fyp.com'],
        ]);

        $response = $this->postJson('/api/users/faculty/import', [
            'file' => $file,
            'program_id' => $this->defaultProgramId(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.created.0.sap_id', 'FAC-101')
            ->assertJsonPath('data.created.0.email', 'imported.faculty@fyp.com');

        $user = User::where('email', 'imported.faculty@fyp.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('FAC-101', $user->registration_no);
        $this->assertTrue($user->hasRole('faculty'));
        $this->assertTrue(password_verify(FacultyImportService::DEFAULT_PASSWORD, $user->password));
    }

    public function test_import_reports_duplicate_email_without_creating_user(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        $existing = User::factory()->create([
            'email' => 'duplicate@fyp.com',
            'registration_no' => 'FAC-999',
        ]);
        $existing->assignRole('faculty');

        Sanctum::actingAs($admin);

        $file = $this->makeFacultySpreadsheet([
            ['SAP ID', 'Name', 'Email'],
            ['FAC-102', 'Another Faculty', 'duplicate@fyp.com'],
        ]);

        $response = $this->postJson('/api/users/faculty/import', [
            'file' => $file,
            'program_id' => $this->defaultProgramId(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.error_count', 1);

        $this->assertSame(1, User::where('email', 'duplicate@fyp.com')->count());
    }

    public function test_admin_can_download_faculty_import_template(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->get('/api/users/faculty/import/template');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );
    }

    protected function makeFacultySpreadsheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'faculty-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'faculty-import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
