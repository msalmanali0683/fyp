<?php

namespace App\Services;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProposalSession;
use App\Models\ProposalSessionReport;
use App\Models\ProposalWorkflowLog;
use App\Models\User;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SessionReportService
{
    /**
     * @return ProposalSessionReport[]
     */
    public function generateRepositoryReports(ProposalSession $session, string $lifecyclePhase, ?User $actor = null): array
    {
        $batchKey = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $directory = "session-repository/{$session->id}/{$lifecyclePhase}/{$batchKey}";
        Storage::disk('public')->makeDirectory($directory);

        $projects = $this->projectsForSnapshot($session);
        $generatedAt = now();

        $files = [
            ProposalSessionReport::TYPE_WORKFLOW_PDF => $this->buildWorkflowPdf($session, $lifecyclePhase, $projects, $directory, $generatedAt),
            ProposalSessionReport::TYPE_STUDENTS_EXCEL => $this->buildStudentsSpreadsheet($session, $directory),
            ProposalSessionReport::TYPE_PROJECTS_EXCEL => $this->buildProjectsSpreadsheet($session, $projects, $directory),
        ];

        $records = [];

        foreach ($files as $type => $fileMeta) {
            $records[] = ProposalSessionReport::create([
                'proposal_session_id' => $session->id,
                'lifecycle_phase' => $lifecyclePhase,
                'report_type' => $type,
                'batch_key' => $batchKey,
                'file_path' => $fileMeta['path'],
                'file_name' => $fileMeta['name'],
                'file_size' => $fileMeta['size'],
                'generated_by' => $actor?->id,
                'generated_at' => $generatedAt,
            ]);
        }

        return $records;
    }

    public function listReports(ProposalSession $session): Collection
    {
        return $session->reports()
            ->with('generator:id,name')
            ->get();
    }

    protected function projectsForSnapshot(ProposalSession $session): Collection
    {
        return Project::query()
            ->with([
                'student:id,name,registration_no,email',
                'supervisor:id,name',
                'members.user:id,name',
                'phases',
                'evaluatorReviews.evaluator:id,name',
                'workflowLogs' => fn ($query) => $query->with('actor:id,name')->orderBy('created_at')->orderBy('id'),
            ])
            ->where('proposal_session_id', $session->id)
            ->orderBy('title')
            ->get();
    }

    protected function buildWorkflowPdf(
        ProposalSession $session,
        string $lifecyclePhase,
        Collection $projects,
        string $directory,
        Carbon $generatedAt,
    ): array {
        $session->loadMissing('program');

        $payload = [
            'sessionName' => $session->name,
            'sessionCode' => $session->code,
            'programName' => $session->program?->name ?? '—',
            'lifecycleLabel' => $this->lifecycleLabel($lifecyclePhase),
            'generatedAt' => $generatedAt->format('Y-m-d H:i:s'),
            'projects' => $projects->map(fn (Project $project) => [
                'title' => $project->title,
                'leader' => $this->formatStudent($project->student),
                'members' => $project->members->map(fn ($member) => $member->user?->name)->filter()->implode(', '),
                'supervisor' => $project->supervisor?->name,
                'status_label' => $this->projectStatusLabel($project),
                'current_phase_label' => FypPhases::label($project->current_phase ?? 'proposal'),
                'proposal_evaluator_marks' => $this->formatEvaluatorMarks($project, 'proposal'),
                'phase_1_evaluator_marks' => $this->formatEvaluatorMarks($project, 'phase_1'),
                'phase_2_evaluator_marks' => $this->formatEvaluatorMarks($project, 'phase_2'),
                'logs' => $project->workflowLogs->map(fn (ProposalWorkflowLog $log) => [
                    'date' => $log->created_at?->format('Y-m-d H:i'),
                    'stage' => FypProposal::stageLabel(FypProposal::displayStage($log->stage)),
                    'fyp_phase' => FypPhases::label($log->fyp_phase ?? 'proposal'),
                    'actor' => $log->actor?->name ?? ($log->actor_role ?: 'System'),
                    'action' => $log->action,
                    'comments' => $log->comments,
                ])->all(),
            ])->all(),
        ];

        $html = view('reports.session-workflow', $payload)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $fileName = "{$session->code}-{$lifecyclePhase}-workflow-report.pdf";
        $path = "{$directory}/{$fileName}";
        Storage::disk('public')->put($path, $dompdf->output());

        return [
            'path' => $path,
            'name' => $fileName,
            'size' => Storage::disk('public')->size($path),
        ];
    }

    protected function buildStudentsSpreadsheet(ProposalSession $session, string $directory): array
    {
        $students = User::query()
            ->role('student')
            ->where('status', 'active')
            ->where('program_id', $session->program_id)
            ->whereRaw('LOWER(session) = ?', [strtolower($session->code)])
            ->orderBy('name')
            ->get(['id', 'name', 'registration_no', 'email', 'program', 'department', 'session']);

        $projectByStudent = Project::query()
            ->with(['student:id,name', 'members.user:id,name'])
            ->where('proposal_session_id', $session->id)
            ->get()
            ->reduce(function (array $assignments, Project $project) {
                $assignments[$project->student_id] = [
                    'project_title' => $project->title,
                    'role' => 'Leader',
                ];

                foreach ($project->members as $member) {
                    $assignments[$member->user_id] = [
                        'project_title' => $project->title,
                        'role' => ucfirst($member->role ?? 'Member'),
                    ];
                }

                return $assignments;
            }, []);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Students');
        $sheet->fromArray([
            ['Name', 'Registration No', 'Email', 'Program', 'Department', 'Session Code', 'In Project', 'Project Title', 'Role'],
        ]);

        $row = 2;
        foreach ($students as $student) {
            $assignment = $projectByStudent[$student->id] ?? null;
            $sheet->fromArray([[
                $student->name,
                $student->registration_no,
                $student->email,
                $student->program,
                $student->department,
                $student->session,
                $assignment ? 'Yes' : 'No',
                $assignment['project_title'] ?? '',
                $assignment['role'] ?? '',
            ]], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $fileName = "{$session->code}-students-list.xlsx";
        $path = "{$directory}/{$fileName}";
        $fullPath = Storage::disk('public')->path($path);
        (new Xlsx($spreadsheet))->save($fullPath);

        return [
            'path' => $path,
            'name' => $fileName,
            'size' => Storage::disk('public')->size($path),
        ];
    }

    protected function buildProjectsSpreadsheet(ProposalSession $session, Collection $projects, string $directory): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Projects');
        $sheet->fromArray([
            [
                'Project Title',
                'Leader',
                'Leader Reg. No',
                'Team Members',
                'Supervisor',
                'Workflow Status',
                'Current Phase',
                'Project Status',
                'Proposal Evaluator Marks',
                'Phase 1 Evaluator Marks',
                'Phase 2 Evaluator Marks',
            ],
        ]);

        $row = 2;
        foreach ($projects as $project) {
            $sheet->fromArray([[
                $project->title,
                $project->student?->name,
                $project->student?->registration_no,
                $project->members->map(fn ($member) => $member->user?->name)->filter()->implode(', '),
                $project->supervisor?->name,
                $this->projectStatusLabel($project),
                FypPhases::label($project->current_phase ?? 'proposal'),
                ucfirst($project->status ?? 'active'),
                $this->formatEvaluatorMarks($project, 'proposal'),
                $this->formatEvaluatorMarks($project, 'phase_1'),
                $this->formatEvaluatorMarks($project, 'phase_2'),
            ]], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $fileName = "{$session->code}-projects-summary.xlsx";
        $path = "{$directory}/{$fileName}";
        $fullPath = Storage::disk('public')->path($path);
        (new Xlsx($spreadsheet))->save($fullPath);

        return [
            'path' => $path,
            'name' => $fileName,
            'size' => Storage::disk('public')->size($path),
        ];
    }

    protected function projectStatusLabel(Project $project): string
    {
        if (in_array($project->current_phase, FypPhases::deliverablePhases(), true)) {
            $deliverable = $project->phases->firstWhere('phase', $project->current_phase);
            if ($deliverable) {
                return FypProposal::deliverableStageLabel(
                    FypProposal::displayDeliverableStage($deliverable->workflow_stage ?? 'draft')
                );
            }
        }

        return FypProposal::stageLabel(
            FypProposal::displayStage($project->workflow_stage ?? 'draft')
        );
    }

    protected function formatEvaluatorMarks(Project $project, string $fypPhase): string
    {
        if (! $project->relationLoaded('evaluatorReviews')) {
            return '—';
        }

        $reviews = $project->evaluatorReviews
            ->where('fyp_phase', $fypPhase)
            ->filter(fn (EvaluatorReview $review) => $review->marks !== null);

        if ($reviews->isEmpty()) {
            return '—';
        }

        return $reviews
            ->map(function (EvaluatorReview $review) {
                $name = $review->evaluator?->name ?? 'Evaluator';
                $decision = str_replace('_', ' ', $review->decision ?? '');

                return "{$name}: {$review->marks}/".($review->max_marks ?? '?')." ({$decision})";
            })
            ->implode('; ');
    }

    protected function formatStudent(?User $student): string
    {
        if (! $student) {
            return '—';
        }

        $parts = array_filter([$student->name, $student->registration_no]);

        return implode(' · ', $parts);
    }

    protected function lifecycleLabel(string $lifecyclePhase): string
    {
        return match ($lifecyclePhase) {
            ProposalSession::LIFECYCLE_PROPOSAL_PHASE => 'Proposal Phase Completed',
            ProposalSession::LIFECYCLE_PHASE_1 => 'Phase 1 Completed',
            ProposalSession::LIFECYCLE_PHASE_2 => 'Phase 2 Completed',
            default => ucwords(str_replace('_', ' ', $lifecyclePhase)),
        };
    }
}
