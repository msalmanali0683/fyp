<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalSession;
use App\Models\ProposalSessionReport;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProposalSessionReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);

        Storage::fake('public');
    }

    public function test_admin_can_generate_session_repository_reports(): void
    {
        [$session, $head, $project] = $this->makeSessionWithProject();

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/generate-reports");

        $response->assertOk();
        $response->assertJsonCount(3, 'data.reports');

        $this->assertDatabaseCount('proposal_session_reports', 3);

        foreach ($response->json('data.reports') as $report) {
            Storage::disk('public')->assertExists(
                ProposalSessionReport::find($report['id'])->file_path
            );
        }
    }

    public function test_session_show_includes_repository_reports(): void
    {
        [$session, $head] = $this->makeSessionWithProject();

        Sanctum::actingAs($head);
        $this->postJson("/api/proposal-sessions/{$session->id}/generate-reports")->assertOk();

        $response = $this->getJson("/api/proposal-sessions/{$session->id}");

        $response->assertOk();
        $response->assertJsonCount(3, 'data.reports');
    }

    public function test_admin_can_download_generated_report(): void
    {
        [$session, $head] = $this->makeSessionWithProject();

        Sanctum::actingAs($head);
        $generate = $this->postJson("/api/proposal-sessions/{$session->id}/generate-reports")->assertOk();
        $report = $generate->json('data.reports.0');

        $download = $this->get("/api/proposal-sessions/{$session->id}/reports/{$report['id']}/download");

        $download->assertOk();
        $download->assertDownload($report['file_name']);
    }

    public function test_complete_proposal_phase_generates_reports_before_purge(): void
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'is_submission_open' => false,
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');
        app(ProgramScopeService::class)->syncMembership($supervisor, $program->id, [], true);

        Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Approved Team',
            'workflow_stage' => 'approved',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        Sanctum::actingAs($head);

        $response = $this->postJson("/api/proposal-sessions/{$session->id}/complete-proposal-phase");

        $response->assertOk();
        $response->assertJsonCount(3, 'data.reports');

        $this->assertDatabaseHas('proposal_session_reports', [
            'proposal_session_id' => $session->id,
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'report_type' => ProposalSessionReport::TYPE_WORKFLOW_PDF,
        ]);
    }

    protected function makeSessionWithProject(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'Fall 2026',
            'code' => 'FA26',
            'is_submission_open' => true,
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
        ]);

        $leader = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => 'FA26',
            'is_proposal_enrolled' => true,
        ]);
        $leader->assignRole('student');

        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');
        app(ProgramScopeService::class)->syncMembership($supervisor, $program->id, [], true);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Smart Campus App',
            'workflow_stage' => 'approved',
            'current_phase' => 'proposal',
            'status' => 'active',
        ]);

        $head = User::factory()->create(['status' => 'active', 'program_id' => $program->id]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        return [$session, $head, $project];
    }
}
