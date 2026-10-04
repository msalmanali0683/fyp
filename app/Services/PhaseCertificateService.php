<?php

namespace App\Services;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProposalWorkflowLog;
use App\Models\User;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PhaseCertificateService
{
    public function __construct(
        private ProposalWorkflowService $workflowService,
    ) {}

    /**
     * @return array<int, array{phase: string, phase_label: string, approved_at: ?string}>
     */
    public function availableCertificates(Project $project): array
    {
        return collect(FypPhases::slugs())
            ->filter(fn (string $phase) => $this->isPhaseApproved($project, $phase))
            ->map(function (string $phase) use ($project) {
                $row = $project->phase($phase);

                return [
                    'phase' => $phase,
                    'phase_label' => FypPhases::label($phase),
                    'approved_at' => $row?->reviewed_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }

    public function isPhaseApproved(Project $project, string $phase): bool
    {
        if (! in_array($phase, FypPhases::slugs(), true)) {
            return false;
        }

        $row = $project->relationLoaded('phases')
            ? $project->phases->firstWhere('phase', $phase)
            : $project->phases()->where('phase', $phase)->first();

        if (! $row) {
            return false;
        }

        if ($row->status !== 'approved') {
            return false;
        }

        if (FypPhases::isDeliverablePhase($phase)) {
            return ($row->workflow_stage ?? 'draft') === 'approved';
        }

        return true;
    }

    public function assertCanDownload(User $user, Project $project, string $phase): void
    {
        if (! in_array($phase, FypPhases::slugs(), true)) {
            throw ValidationException::withMessages([
                'phase' => ['Invalid FYP phase.'],
            ]);
        }

        if (! $this->workflowService->isProjectMember($user, $project)) {
            throw ValidationException::withMessages([
                'project' => ['Only project team members can download phase certificates.'],
            ]);
        }

        if (! $this->isPhaseApproved($project, $phase)) {
            throw ValidationException::withMessages([
                'phase' => ['This phase has not been approved by the Committee Head yet.'],
            ]);
        }
    }

    public function fileName(Project $project, string $phase): string
    {
        $sessionCode = $project->proposalSession?->code ?? 'fyp';
        $safeCode = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $sessionCode) ?: 'fyp';

        return "{$safeCode}-{$phase}-certificate.pdf";
    }

    public function generatePdf(Project $project, string $phase): string
    {
        $project->loadMissing([
            'student:id,name,registration_no,email',
            'supervisor:id,name',
            'members.user:id,name,registration_no',
            'proposalSession:id,code,name,program_id',
            'proposalSession.program:id,name',
            'phases.reviewer:id,name',
            'evaluatorReviews.evaluator:id,name',
            'workflowLogs' => fn ($query) => $query->with('actor:id,name')->orderBy('created_at')->orderBy('id'),
        ]);

        $html = view('certificates.phase-certificate', $this->buildViewData($project, $phase))->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildViewData(Project $project, string $phase): array
    {
        $phaseRow = $project->phase($phase);
        $reviews = $this->evaluatorReviewsForPhase($project, $phase);
        $averageMarks = $this->averageMarks($reviews);
        $logs = $this->phaseWorkflowLogs($project, $phase);

        return [
            'certificateTitle' => 'Certificate of Completion',
            'phaseLabel' => FypPhases::label($phase),
            'sessionName' => $project->proposalSession?->name ?? 'FYP Session',
            'sessionCode' => $project->proposalSession?->code ?? '—',
            'programName' => $project->proposalSession?->program?->name ?? ($project->program?->name ?? '—'),
            'projectTitle' => $project->title,
            'leader' => $this->formatStudent($project->student),
            'teamMembers' => $project->members
                ->map(fn ($member) => $this->formatStudent($member->user))
                ->filter()
                ->implode(', '),
            'supervisor' => $project->supervisor?->name ?? 'Not assigned',
            'approvedAt' => $phaseRow?->reviewed_at?->format('d F Y') ?? now()->format('d F Y'),
            'approvedBy' => $phaseRow?->reviewer?->name ?? 'Committee Head',
            'certificateRef' => strtoupper("{$project->proposalSession?->code}-{$project->id}-{$phase}"),
            'generatedAt' => now()->format('d F Y H:i'),
            'evaluatorReviews' => $reviews->map(fn (EvaluatorReview $review) => [
                'evaluator' => $review->evaluator?->name ?? 'Evaluator',
                'marks' => $review->marks,
                'maxMarks' => $review->max_marks,
                'decision' => ucwords(str_replace('_', ' ', $review->decision ?? '')),
                'comments' => $review->comments,
            ])->all(),
            'averageMarks' => $averageMarks,
            'averageMaxMarks' => $reviews->first()?->max_marks,
            'workflowLogs' => $logs->map(fn (ProposalWorkflowLog $log) => [
                'date' => $log->created_at?->format('d M Y H:i'),
                'stage' => $this->logStageLabel($phase, $log->stage),
                'actor' => $log->actor?->name ?? ($log->actor_role ?: 'System'),
                'action' => $log->action,
                'comments' => $log->comments,
            ])->all(),
        ];
    }

    protected function evaluatorReviewsForPhase(Project $project, string $phase): Collection
    {
        return $project->evaluatorReviews
            ->where('fyp_phase', $phase)
            ->filter(fn (EvaluatorReview $review) => $review->marks !== null)
            ->values();
    }

    protected function averageMarks(Collection $reviews): ?float
    {
        if ($reviews->isEmpty()) {
            return null;
        }

        return round($reviews->avg('marks'), 1);
    }

    protected function phaseWorkflowLogs(Project $project, string $phase): Collection
    {
        return $project->workflowLogs
            ->filter(fn (ProposalWorkflowLog $log) => ($log->fyp_phase ?? 'proposal') === $phase)
            ->values();
    }

    protected function logStageLabel(string $phase, string $stage): string
    {
        if (FypPhases::isDeliverablePhase($phase)) {
            return FypProposal::deliverableStageLabel(
                FypProposal::displayDeliverableStage($stage)
            );
        }

        return FypProposal::stageLabel(FypProposal::displayStage($stage));
    }

    protected function formatStudent(?User $student): string
    {
        if (! $student) {
            return '';
        }

        $parts = array_filter([$student->name, $student->registration_no]);

        return implode(' · ', $parts);
    }
}
