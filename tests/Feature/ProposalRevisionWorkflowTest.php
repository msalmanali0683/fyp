<?php

namespace Tests\Feature;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProposalRevisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('fyp.proposal.document_disk', 'public'));
    }

    protected function createRevisionProject(): array
    {
        $leader = User::factory()->create(['status' => 'active', 'is_proposal_enrolled' => true]);
        $leader->assignRole('student');

        $supervisor = User::factory()->create(['status' => 'active']);
        $supervisor->assignRole(['faculty', 'supervisor']);

        $evaluator = User::factory()->create(['status' => 'active']);
        $evaluator->assignRole(['faculty', 'evaluator']);

        $project = Project::create([
            'student_id' => $leader->id,
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'accepted',
            'title' => 'Revision Workflow Test Project',
            'description' => 'Test project for supervisor revision gate.',
            'academic_year' => '2025-2026',
            'current_phase' => 'proposal',
            'workflow_stage' => 'revision_required',
            'evaluator_resubmit_mode' => 'negative_only',
            'proposal_submitted_at' => now(),
            'status' => 'active',
        ]);

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            ProjectPhase::create([
                'project_id' => $project->id,
                'phase' => $phase,
                'content' => $phase === 'proposal' ? 'Old proposal.pdf' : null,
                'status' => 'draft',
            ]);
        }

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $leader->id,
            'role' => 'leader',
        ]);

        ProjectEvaluator::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
        ]);

        EvaluatorReview::create([
            'project_id' => $project->id,
            'evaluator_id' => $evaluator->id,
            'decision' => 'revision_required',
            'comments' => 'Please improve the literature review.',
            'reviewed_at' => now(),
        ]);

        return compact('leader', 'supervisor', 'evaluator', 'project');
    }

    public function test_student_resubmit_sends_proposal_to_supervisor_for_review(): void
    {
        ['leader' => $leader, 'supervisor' => $supervisor, 'project' => $project] = $this->createRevisionProject();

        Sanctum::actingAs($leader);

        $response = $this->post("/api/projects/{$project->id}/resubmit", [
            'proposal_file' => UploadedFile::fake()->create('revised-proposal.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'supervisor_revision_pending');

        $project->refresh();
        $this->assertSame('supervisor_revision_pending', $project->workflow_stage);
        $this->assertNull($project->supervisor_revision_feedback);
    }

    public function test_supervisor_can_proceed_revised_proposal_to_evaluator_review(): void
    {
        ['leader' => $leader, 'supervisor' => $supervisor, 'project' => $project] = $this->createRevisionProject();

        Sanctum::actingAs($leader);
        $this->post("/api/projects/{$project->id}/resubmit", [
            'proposal_file' => UploadedFile::fake()->create('revised-proposal.pdf', 100, 'application/pdf'),
        ])->assertOk();

        Sanctum::actingAs($supervisor);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor/revision-review", [
            'proceed' => true,
            'feedback' => 'Looks good. Proceeding.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'evaluator_review');

        $project->refresh();
        $this->assertSame('evaluator_review', $project->workflow_stage);
        $this->assertDatabaseMissing('evaluator_reviews', [
            'project_id' => $project->id,
            'decision' => 'revision_required',
        ]);
    }

    public function test_supervisor_can_return_revised_proposal_to_students_with_comments(): void
    {
        ['leader' => $leader, 'supervisor' => $supervisor, 'project' => $project] = $this->createRevisionProject();

        Sanctum::actingAs($leader);
        $this->post("/api/projects/{$project->id}/resubmit", [
            'proposal_file' => UploadedFile::fake()->create('revised-proposal.pdf', 100, 'application/pdf'),
        ])->assertOk();

        Sanctum::actingAs($supervisor);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor/revision-review", [
            'proceed' => false,
            'feedback' => 'Please update the evaluation section before resubmitting.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'revision_required')
            ->assertJsonPath('data.supervisor_revision_feedback', 'Please update the evaluation section before resubmitting.');

        $project->refresh();
        $this->assertSame('revision_required', $project->workflow_stage);
        $this->assertSame('Please update the evaluation section before resubmitting.', $project->supervisor_revision_feedback);
    }

    public function test_committee_head_can_approve_revised_proposal_on_supervisors_behalf(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createRevisionProject();

        Sanctum::actingAs($leader);
        $this->post("/api/projects/{$project->id}/resubmit", [
            'proposal_file' => UploadedFile::fake()->create('revised-proposal.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $head = User::factory()->create(['status' => 'active']);
        $head->assignRole('fyp-committee-head');

        Sanctum::actingAs($head);
        $response = $this->postJson("/api/projects/{$project->id}/supervisor/revision-review", [
            'proceed' => true,
            'feedback' => 'Approved on behalf of the supervisor.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.workflow_stage', 'evaluator_review');

        $project->refresh();
        $this->assertSame('evaluator_review', $project->workflow_stage);
    }

    public function test_unrelated_faculty_cannot_review_revision_on_supervisors_behalf(): void
    {
        ['leader' => $leader, 'project' => $project] = $this->createRevisionProject();

        Sanctum::actingAs($leader);
        $this->post("/api/projects/{$project->id}/resubmit", [
            'proposal_file' => UploadedFile::fake()->create('revised-proposal.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $otherFaculty = User::factory()->create(['status' => 'active']);
        $otherFaculty->assignRole('faculty');

        Sanctum::actingAs($otherFaculty);
        $this->postJson("/api/projects/{$project->id}/supervisor/revision-review", [
            'proceed' => true,
        ])->assertStatus(422);

        $this->assertSame('supervisor_revision_pending', $project->fresh()->workflow_stage);
    }
}
