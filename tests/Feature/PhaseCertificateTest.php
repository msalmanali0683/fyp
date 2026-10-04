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
use App\Models\ProposalWorkflowLog;
use App\Models\User;
use App\Services\PhaseCertificateService;
use App\Services\ProgramScopeService;
use Database\Seeders\DepartmentProgramSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseCertificateTest extends TestCase
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

    public function test_student_can_download_phase_one_certificate_after_committee_head_approval(): void
    {
        [$leader, , , , , , $project] = $this->runApprovedPhaseOneWorkflow();

        Sanctum::actingAs($leader);

        $response = $this->get("/api/projects/{$project->id}/phases/phase_1/certificate");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_student_cannot_download_certificate_before_phase_is_approved(): void
    {
        [$leader, , , , , , $project] = $this->makePhaseWorkflowFixtures();

        Sanctum::actingAs($leader);

        $this->get("/api/projects/{$project->id}/phases/phase_1/certificate")
            ->assertStatus(422);
    }

    public function test_non_member_cannot_download_certificate(): void
    {
        [$leader, , , , , , $project, $session] = $this->runApprovedPhaseOneWorkflow();
        $program = Program::where('code', 'CS')->firstOrFail();
        $outsider = $this->makeStudent($program, $session->code);

        Sanctum::actingAs($outsider);

        $this->get("/api/projects/{$project->id}/phases/phase_1/certificate")
            ->assertStatus(422);
    }

    public function test_my_project_includes_approved_certificates_for_students(): void
    {
        [$leader, , , , , , $project] = $this->runApprovedPhaseOneWorkflow();

        Sanctum::actingAs($leader);

        $response = $this->getJson('/api/proposals/my-project')->assertOk();

        $this->assertCount(2, $response->json('data.project.approved_certificates'));
        $this->assertSame('proposal', $response->json('data.project.approved_certificates.0.phase'));
        $this->assertSame('phase_1', $response->json('data.project.approved_certificates.1.phase'));
    }

    public function test_certificate_workflow_history_is_limited_to_requested_phase(): void
    {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project] = $this->makePhaseWorkflowFixtures();

        ProposalWorkflowLog::create([
            'project_id' => $project->id,
            'fyp_phase' => 'proposal',
            'stage' => 'approved',
            'action' => 'Proposal approved by Committee Head',
            'actor_id' => $head->id,
            'actor_role' => 'fyp-committee-head',
        ]);

        $this->runApprovedPhaseOneWorkflow($leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project);

        $project = $project->fresh();
        $project->load([
            'student',
            'supervisor',
            'members.user',
            'proposalSession.program',
            'phases.reviewer',
            'evaluatorReviews.evaluator',
            'workflowLogs.actor',
        ]);

        $service = app(PhaseCertificateService::class);
        $pdf = $service->generatePdf($project, 'phase_1');
        $this->assertStringStartsWith('%PDF', $pdf);

        $phaseLogs = $project->workflowLogs->filter(fn ($log) => ($log->fyp_phase ?? 'proposal') === 'phase_1');
        $proposalLogs = $project->workflowLogs->filter(fn ($log) => ($log->fyp_phase ?? 'proposal') === 'proposal');

        $this->assertGreaterThan(0, $phaseLogs->count());
        $this->assertGreaterThan(0, $proposalLogs->count());
        $this->assertTrue($phaseLogs->every(fn ($log) => $log->fyp_phase === 'phase_1'));
    }

    protected function runApprovedPhaseOneWorkflow(
        ?User $leader = null,
        ?User $supervisor = null,
        ?User $evaluator1 = null,
        ?User $evaluator2 = null,
        ?User $committee = null,
        ?User $head = null,
        ?Project $project = null,
    ): array {
        [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project, $session] = $this->makePhaseWorkflowFixtures(
            $leader,
            $supervisor,
            $evaluator1,
            $evaluator2,
            $committee,
            $head,
            $project,
        );

        Sanctum::actingAs($leader);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/attachment", [
            'file' => UploadedFile::fake()->create('phase-1.pdf', 100, 'application/pdf'),
        ])->assertOk();
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/submit")->assertOk();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/supervisor/respond", [
            'accept' => true,
        ])->assertOk();

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluators/keep")->assertOk();

        $question = $this->questionFor('phase_1');

        Sanctum::actingAs($evaluator1);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 90],
            ],
        ])->assertOk();

        Sanctum::actingAs($evaluator2);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/evaluator-review", [
            'decision' => 'accepted',
            'comments' => 'Approved.',
            'answers' => [
                ['question_id' => $question->id, 'marks' => 86],
            ],
        ])->assertOk();

        Sanctum::actingAs($committee);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-final", [
            'approve' => true,
        ])->assertOk();

        Sanctum::actingAs($head);
        $this->postJson("/api/projects/{$project->id}/phases/phase_1/committee-head-approve", [
            'approve' => true,
        ])->assertOk();

        return [$leader, $supervisor, $evaluator1, $evaluator2, $committee, $head, $project->fresh(), $session];
    }

    protected function makePhaseWorkflowFixtures(
        ?User $leader = null,
        ?User $supervisor = null,
        ?User $evaluator1 = null,
        ?User $evaluator2 = null,
        ?User $committee = null,
        ?User $head = null,
        ?Project $project = null,
    ): array {
        $program = Program::where('code', 'CS')->firstOrFail();

        if ($project) {
            $session = $project->proposalSession;
            $leader ??= User::findOrFail($project->student_id);
            $supervisor ??= User::findOrFail($project->supervisor_id);
        } else {
            $session = ProposalSession::create([
                'program_id' => $program->id,
                'name' => 'SP 2026',
                'code' => 'SP2026',
                'is_submission_open' => true,
                'is_fully_locked' => false,
                'status' => 'active',
                'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
            ]);
        }

        $leader ??= $this->makeStudent($program, $session->code);
        $supervisor ??= $this->makeSupervisor($program);
        $evaluator1 ??= $this->makeEvaluator($program, 'eval1@fyp.com');
        $evaluator2 ??= $this->makeEvaluator($program, 'eval2@fyp.com');
        $committee ??= $this->makeCommitteeMember($program);
        $head ??= $this->makeCommitteeHead($program);

        if (! $project) {
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
                    'marks' => 88,
                    'reviewed_at' => now(),
                ]);
            }
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
