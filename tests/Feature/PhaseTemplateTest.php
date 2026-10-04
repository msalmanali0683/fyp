<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
        Storage::fake('public');
    }

    public function test_admin_can_upload_a_template(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/phase-templates', [
            'phase' => 'proposal',
            'title' => 'Proposal Format',
            'file' => UploadedFile::fake()->create('proposal-template.docx', 200),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Proposal Format');
        $response->assertJsonPath('data.phase', 'proposal');

        $this->assertDatabaseHas('phase_templates', [
            'phase' => 'proposal',
            'title' => 'Proposal Format',
            'uploaded_by' => $admin->id,
        ]);
    }

    public function test_non_privileged_user_cannot_upload_a_template(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $this->postJson('/api/phase-templates', [
            'phase' => 'phase_1',
            'file' => UploadedFile::fake()->create('template.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_only_pdf_and_word_files_are_allowed(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);

        $this->postJson('/api/phase-templates', [
            'phase' => 'phase_1',
            'file' => UploadedFile::fake()->create('template.exe', 100),
        ])->assertStatus(422);
    }

    public function test_any_authenticated_user_can_list_and_download_templates(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $this->postJson('/api/phase-templates', [
            'phase' => 'phase_2',
            'file' => UploadedFile::fake()->create('phase2-template.pdf', 150, 'application/pdf'),
        ])->assertCreated();

        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Sanctum::actingAs($student);
        $response = $this->getJson('/api/phase-templates');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.phase_2'));
        $this->assertFalse($response->json('data.can_manage'));
        $this->assertNotEmpty($response->json('data.phase_2.0.download_url'));
    }

    public function test_admin_can_delete_a_template(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/phase-templates', [
            'phase' => 'proposal',
            'file' => UploadedFile::fake()->create('template.pdf', 100, 'application/pdf'),
        ])->json('data');

        $this->deleteJson("/api/phase-templates/{$created['id']}")->assertOk();

        $this->assertDatabaseMissing('phase_templates', ['id' => $created['id']]);
    }

    public function test_student_cannot_delete_a_template(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/phase-templates', [
            'phase' => 'proposal',
            'file' => UploadedFile::fake()->create('template.pdf', 100, 'application/pdf'),
        ])->json('data');

        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Sanctum::actingAs($student);
        $this->deleteJson("/api/phase-templates/{$created['id']}")->assertStatus(422);

        $this->assertDatabaseHas('phase_templates', ['id' => $created['id']]);
    }

    public function test_committee_member_without_permission_cannot_upload(): void
    {
        $member = User::factory()->create(['status' => 'active']);
        $member->assignRole('fyp-committee-member');

        Sanctum::actingAs($member);
        $this->postJson('/api/phase-templates', [
            'phase' => 'proposal',
            'file' => UploadedFile::fake()->create('template.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }
}
