<?php

namespace Tests\Feature;

use App\Models\EvaluationQuestion;
use App\Models\EvaluatorReview;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\ProposalSession;
use App\Models\User;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseDeliverableWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DepartmentProgramSeeder::class);
        Storage::fake('public');

        foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
            EvaluationQuestion::create([
                'phase' => $phase,
                'text' => 'Overall Quality',
                'max_marks' => 100,
                'order' => 1,
                'is_active' => true,
            ]);
        }
    }

    protected function questionFor(string $phase): EvaluationQuestion
    {
        return EvaluationQuestion::where('phase', $phase)->firstOrFail();
    }

    protected function answerPayload(string $phase, int $marks): array
    {
        return [['question_id' => $this->questionFor($phase)->id, 'marks' => $marks]];
    }

    public function test_phase_one_full_workflow_pipeline(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project, $session] = $this->makePhaseWorkflowFixtures();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1.pdf', 100, 'application/pdf'),
        ])->assertOk();

        $this->postJson("/api/projects/{$project->id}/phases/phase_1/submit")->assertOk();
        $this->assertSame('supervisor_review', $project->fresh()->phase('phase_1')->workflow_stage);

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/supervisor/respond", [
            'accept' => true,
            'feedback' => 'Looks good',
        ])->assertOk();
        $this->assertSame('committee_review', $project->fresh()->phase('phase_1')->workflow_stage);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluators/keep")->assertOk();
        $this->assertSame('evaluator_review', $project->fresh()->phase('phase_1')->workflow_stage);

        Sanctum::actingAs($evaluator1);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved',
            'answers' => $this->answerPayload('phase_1', 90),
        ])->assertOk();

        Sanctum::actingAs($evaluator2);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved',
            'answers' => $this->answerPayload('phase_1', 86),
        ])->assertOk();
        $this->assertSame('committee_final', $project->fresh()->phase('phase_1')->workflow_stage);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-final", [
            'approve' => true,
        ])->assertOk();
        $this->assertSame('committee_head_approval', $project->fresh()->phase('phase_1')->workflow_stage);

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-head-approve", [
            'approve' => true,
        ])->assertOk();

        $phaseRow = $project->fresh()->phase('phase_1');
        $this->assertSame('approved', $phaseRow->workflow_stage);
        $this->assertSame('approved', $phaseRow->status);
    }

    public function test_committee_can_send_phase_deliverable_back_to_students(): void
    {
        [$leader, $supervisor, , , $committee, , $project] = $this->makePhaseWorkflowFixtures();

        $this->seedSubmittedToCommittee($project, $leader, $supervisor);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-return", [
            'comments' => 'Please revise the report structure.',
        ])->assertOk();

        $row = $project->fresh()->phase('phase_1');
        $this->assertSame('revision_required', $row->workflow_stage);
        $this->assertSame('revision_required', $row->status);
    }

    public function test_committee_head_can_approve_revised_phase_deliverable_on_supervisors_behalf(): void
    {
        [$leader, $supervisor, , , $committee, $head, $project] = $this->makePhaseWorkflowFixtures();

        $this->seedSubmittedToCommittee($project, $leader, $supervisor);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-return", [
            'comments' => 'Please revise the report structure.',
        ])->assertOk();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1-revised.pdf', 100, 'application/pdf'),
        ])->assertOk();
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/resubmit")->assertOk();
        $this->assertSame('supervisor_revision_pending', $project->fresh()->phase('phase_1')->workflow_stage);

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/supervisor/revision-review", [
            'proceed' => true,
            'feedback' => 'Approved on behalf of the supervisor.',
        ])->assertOk();

        $row = $project->fresh()->phase('phase_1');
        $this->assertContains($row->workflow_stage, ['evaluator_review', 'committee_final']);
    }

    public function test_unrelated_faculty_cannot_review_phase_revision_on_supervisors_behalf(): void
    {
        [$leader, $supervisor, , , $committee, , $project] = $this->makePhaseWorkflowFixtures();

        $this->seedSubmittedToCommittee($project, $leader, $supervisor);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-return", [
            'comments' => 'Please revise the report structure.',
        ])->assertOk();

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1-revised.pdf', 100, 'application/pdf'),
        ])->assertOk();
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/resubmit")->assertOk();

        $otherFaculty = User::factory()->create(['status' => 'active']);
        $otherFaculty->assignRole('faculty');

        Sanctum::actingAs($otherFaculty);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/supervisor/revision-review", [
            'proceed' => true,
        ])->assertStatus(422);

        $this->assertSame('supervisor_revision_pending', $project->fresh()->phase('phase_1')->workflow_stage);
    }

    public function test_student_cannot_edit_or_resubmit_after_supervisor_forwards_to_committee(): void
    {
        [$leader, $supervisor, , , $committee, , $project] = $this->makePhaseWorkflowFixtures();

        $this->seedSubmittedToCommittee($project, $leader, $supervisor);

        $row = $project->fresh()->phase('phase_1');
        $this->assertSame('committee_review', $row->workflow_stage);
        $this->assertFalse($row->isEditable());

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/submit")
            ->assertStatus(422);

        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1-v2.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluators/keep")->assertOk();
        $this->assertSame('evaluator_review', $project->fresh()->phase('phase_1')->workflow_stage);
    }

    public function test_committee_can_reassign_evaluators_during_phase_committee_review(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, , $project] = $this->makePhaseWorkflowFixtures();

        $this->seedSubmittedToCommittee($project, $leader, $supervisor);

        $replacement = $this->makeEvaluator($project->program, 'replacement-eval@fyp.com');

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluators", [
            'evaluator_ids' => [$evaluator1->id, $replacement->id],
        ])->assertOk();

        $this->assertSame('evaluator_review', $project->fresh()->phase('phase_1')->workflow_stage);
        $this->assertSame(
            [$evaluator1->id, $replacement->id],
            $project->fresh()->evaluators()->pluck('evaluator_id')->sort()->values()->all()
        );
    }

    public function test_committee_head_can_submit_phase_evaluator_review_on_behalf(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project] = $this->makePhaseWorkflowFixtures();

        $this->seedSubmittedToCommittee($project, $leader, $supervisor);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluators/keep")->assertOk();

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Submitted by committee head on behalf of evaluator.',
            'evaluator_id' => $evaluator1->id,
            'answers' => $this->answerPayload('phase_1', 92),
        ])->assertOk();

        $this->assertDatabaseHas('evaluator_reviews', [
            'project_id' => $project->id,
            'evaluator_id' => $evaluator1->id,
            'fyp_phase' => 'phase_1',
            'decision' => 'accepted',
        ]);
    }

    public function test_committee_head_can_reevaluate_approved_phase_keeping_same_evaluators(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project] = $this->makePhaseWorkflowFixtures();

        $this->driveProjectToApproved($project, $leader, $supervisor, $committee, $head, $evaluator1, $evaluator2);

        Sanctum::actingAs($head);
        $deadline = now()->addDays(3)->toDateString();

        $response = $this->postJson("/api/projects/{$project->id}/phases/phase_1/reevaluate", [
            'keep_same_evaluators' => true,
            'extended_deadline' => $deadline,
            'notes' => 'Marks looked inconsistent, redoing the review.',
        ]);

        $response->assertOk();

        $row = $project->fresh()->phase('phase_1');
        $this->assertSame('under_review', $row->status);
        $this->assertSame('evaluator_review', $row->workflow_stage);
        $this->assertTrue((bool) $row->is_reevaluation);
        $this->assertSame($deadline, $row->reevaluation_deadline->toDateString());

        // Same evaluators are kept, but their prior reviews were cleared for a fresh pass.
        $this->assertDatabaseHas('project_evaluators', ['project_id' => $project->id, 'evaluator_id' => $evaluator1->id]);
        $this->assertDatabaseHas('project_evaluators', ['project_id' => $project->id, 'evaluator_id' => $evaluator2->id]);
        $this->assertDatabaseMissing('evaluator_reviews', ['project_id' => $project->id, 'fyp_phase' => 'phase_1']);

        // The evaluators can resubmit their reviews on the reopened phase.
        Sanctum::actingAs($evaluator1);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Redone.',
            'answers' => $this->answerPayload('phase_1', 95),
        ])->assertOk();
    }

    public function test_committee_head_can_reevaluate_with_new_evaluators(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project] = $this->makePhaseWorkflowFixtures();
        $program = $project->program;

        $this->driveProjectToApproved($project, $leader, $supervisor, $committee, $head, $evaluator1, $evaluator2);

        $newEvaluator1 = $this->makeEvaluator($program, 'new-eval1@fyp.com');
        $newEvaluator2 = $this->makeEvaluator($program, 'new-eval2@fyp.com');

        Sanctum::actingAs($head);
        $response = $this->postJson("/api/projects/{$project->id}/phases/phase_1/reevaluate", [
            'keep_same_evaluators' => false,
            'evaluator_ids' => [$newEvaluator1->id, $newEvaluator2->id],
            'extended_deadline' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('project_evaluators', ['project_id' => $project->id, 'evaluator_id' => $evaluator1->id]);
        $this->assertDatabaseHas('project_evaluators', ['project_id' => $project->id, 'evaluator_id' => $newEvaluator1->id]);
        $this->assertDatabaseHas('project_evaluators', ['project_id' => $project->id, 'evaluator_id' => $newEvaluator2->id]);
    }

    public function test_cannot_reevaluate_a_phase_that_is_not_approved(): void
    {
        [, , , , , $head, $project] = $this->makePhaseWorkflowFixtures();

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/reevaluate", [
            'keep_same_evaluators' => true,
            'extended_deadline' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_reevaluate(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project] = $this->makePhaseWorkflowFixtures();

        $this->driveProjectToApproved($project, $leader, $supervisor, $committee, $head, $evaluator1, $evaluator2);

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/reevaluate", [
            'keep_same_evaluators' => true,
            'extended_deadline' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_reevaluating_completed_phase2_project_does_not_change_project_status(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project] = $this->makePhaseWorkflowFixtures();
        $project->update(['current_phase' => 'phase_2']);
        $project->phases()->where('phase', 'phase_1')->update(['status' => 'approved', 'workflow_stage' => 'approved']);
        ProjectPhase::create(['project_id' => $project->id, 'phase' => 'phase_2', 'status' => 'draft', 'workflow_stage' => 'draft']);

        $this->driveProjectToApproved($project, $leader, $supervisor, $committee, $head, $evaluator1, $evaluator2, 'phase_2');
        $project->update(['status' => 'completed']);

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/phase_2/reevaluate", [
            'keep_same_evaluators' => true,
            'extended_deadline' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $fresh = $project->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('phase_2', $fresh->current_phase);
        $this->assertSame('under_review', $fresh->phase('phase_2')->status);
    }

    protected function driveProjectToApproved(
        Project $project,
        User $leader,
        User $supervisor,
        User $committee,
        User $head,
        User $evaluator1,
        User $evaluator2,
        string $phase = 'phase_1'
    ): void {
        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/attachment", [
            'file' => UploadedFile::fake()->create("{$phase}.pdf", 100, 'application/pdf'),
        ])->assertOk();
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/submit")->assertOk();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/supervisor/respond", [
            'accept' => true,
        ])->assertOk();

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/evaluators/keep")->assertOk();

        Sanctum::actingAs($evaluator1);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
            'answers' => $this->answerPayload($phase, 90),
        ])->assertOk();

        Sanctum::actingAs($evaluator2);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
            'answers' => $this->answerPayload($phase, 86),
        ])->assertOk();

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/committee-final", [
            'approve' => true,
        ])->assertOk();

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/{$phase}/committee-head-approve", [
            'approve' => true,
        ])->assertOk();
    }

    protected function seedSubmittedToCommittee(Project $project, User $leader, User $supervisor): void
    {
        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1.pdf', 100, 'application/pdf'),
        ])->assertOk();
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/submit")->assertOk();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/supervisor/respond", [
            'accept' => true,
        ])->assertOk();
    }

    protected function makePhaseWorkflowFixtures(): array
    {
        $program = Program::where('code', 'CS')->firstOrFail();

        $session = ProposalSession::create([
            'program_id' => $program->id,
            'name' => 'SP 2026',
            'code' => 'SP2026',
            'is_submission_open' => true,
            'is_fully_locked' => false,
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
        ]);

        $leader = $this->makeStudent($program, 'SP2026');
        $supervisor = $this->makeSupervisor($program);
        $evaluator1 = $this->makeEvaluator($program, 'eval1@fyp.com');
        $evaluator2 = $this->makeEvaluator($program, 'eval2@fyp.com');
        $committee = $this->makeCommitteeMember($program);
        $head = $this->makeCommitteeHead($program);

        $project = Project::create([
            'student_id' => $leader->id,
            'program_id' => $program->id,
            'proposal_session_id' => $session->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Workflow Test Project',
            'workflow_stage' => 'approved',
            'current_phase' => 'phase_1',
            'status' => 'active',
        ]);

        ProjectPhase::create(['project_id' => $project->id, 'phase' => 'proposal', 'status' => 'approved', 'workflow_stage' => 'approved']);
        ProjectPhase::create(['project_id' => $project->id, 'phase' => 'phase_1', 'status' => 'draft', 'workflow_stage' => 'draft']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $leader->id, 'role' => 'leader', 'status' => 'active']);

        foreach ([$evaluator1, $evaluator2] as $evaluator) {
            ProjectEvaluator::create([
                'project_id' => $project->id,
                'evaluator_id' => $evaluator->id,
                'assigned_by' => $committee->id,
            ]);
            EvaluatorReview::create([
                'project_id' => $project->id,
                'evaluator_id' => $evaluator->id,
                'fyp_phase' => 'proposal',
                'decision' => 'accepted',
                'reviewed_at' => now(),
            ]);
        }

        return [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project, $session];
    }

    protected function makeStudent(Program $program, string $sessionCode): User
    {
        $student = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
            'session' => $sessionCode,
            'is_proposal_enrolled' => true,
        ]);
        $student->assignRole('student');

        return $student;
    }

    protected function makeSupervisor(Program $program): User
    {
        $supervisor = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $supervisor->assignRole('supervisor');
        app(ProgramScopeService::class)->syncMembership($supervisor, (int) $program->id, [], true);

        return $supervisor;
    }

    protected function makeEvaluator(Program $program, string $email): User
    {
        $evaluator = User::factory()->create([
            'status' => 'active',
            'email' => $email,
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $evaluator->assignRole('evaluator');
        app(ProgramScopeService::class)->syncMembership($evaluator, (int) $program->id, [], true);

        return $evaluator;
    }

    protected function makeCommitteeMember(Program $program): User
    {
        $member = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $member->assignRole('fyp-committee-member');
        app(ProgramScopeService::class)->syncMembership($member, (int) $program->id, [
            'is_committee_member' => true,
        ], true);

        return $member;
    }

    protected function makeCommitteeHead(Program $program): User
    {
        $head = User::factory()->create([
            'status' => 'active',
            'program_id' => $program->id,
            'department_id' => $program->department_id,
        ]);
        $head->assignRole('fyp-committee-head');
        app(ProgramScopeService::class)->syncMembership($head, (int) $program->id, [
            'is_committee_head' => true,
            'is_committee_member' => true,
        ], true);

        return $head;
    }
}
