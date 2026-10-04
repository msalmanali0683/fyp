<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Project;
use App\Support\FypPhases;
use App\Support\FypProposal;

$id = (int) ($argv[1] ?? 6);

$project = Project::with([
    'student:id,name,email',
    'supervisor:id,name,email',
    'phases',
    'evaluators.evaluator:id,name',
    'evaluatorReviews.evaluator:id,name',
    'workflowLogs' => fn ($q) => $q->orderByDesc('id')->limit(8),
])->find($id);

if (! $project) {
    echo "Project {$id} not found\n";
    exit(1);
}

$phase = $project->current_phase;
$deliverable = in_array($phase, FypPhases::deliverablePhases(), true)
    ? $project->phases->firstWhere('phase', $phase)
    : null;

$workflowStage = $deliverable
    ? ($deliverable->workflow_stage ?? 'draft')
    : ($project->workflow_stage ?? 'draft');

$displayStage = $deliverable
    ? FypProposal::displayDeliverableStage($workflowStage)
    : FypProposal::displayStage($workflowStage);

$stageLabel = $deliverable
    ? FypProposal::deliverableStageLabel($displayStage)
    : FypProposal::stageLabel($displayStage);

echo json_encode([
    'title' => $project->title,
    'current_phase' => $phase,
    'current_phase_label' => FypPhases::label($phase),
    'workflow_stage' => $workflowStage,
    'display_stage' => $displayStage,
    'workflow_stage_label' => $stageLabel,
    'project_status' => $project->status,
    'leader' => $project->student?->only(['id', 'name', 'email']),
    'supervisor' => $project->supervisor?->only(['id', 'name', 'email']),
    'deliverable_row' => $deliverable ? [
        'phase' => $deliverable->phase,
        'status' => $deliverable->status,
        'workflow_stage' => $deliverable->workflow_stage,
        'is_editable' => $deliverable->isEditable(),
        'submitted_at' => $deliverable->submitted_at?->toDateTimeString(),
        'feedback' => $deliverable->feedback,
        'has_attachment' => filled($deliverable->attachment),
    ] : null,
    'evaluators' => $project->evaluators->map(fn ($e) => [
        'id' => $e->evaluator_id,
        'name' => $e->evaluator?->name,
    ])->values(),
    'evaluator_reviews' => $project->evaluatorReviews->map(fn ($r) => [
        'fyp_phase' => $r->fyp_phase,
        'evaluator' => $r->evaluator?->name,
        'decision' => $r->decision,
    ])->values(),
    'recent_workflow_logs' => $project->workflowLogs->map(fn ($l) => [
        'stage' => $l->stage,
        'fyp_phase' => $l->fyp_phase,
        'action' => $l->action,
        'actor_role' => $l->actor_role,
        'created_at' => $l->created_at?->toDateTimeString(),
    ])->values(),
    'who_can_act' => describeActors($workflowStage, $deliverable),
], JSON_PRETTY_PRINT)."\n";

function describeActors(string $workflowStage, $deliverable): array
{
    return match ($workflowStage) {
        'draft' => [
            'student_leader' => 'Upload PDF and submit deliverable',
            'others' => 'No action required',
        ],
        'supervisor_review' => [
            'supervisor' => 'Approve deliverable or return for revision',
            'student_leader' => 'Wait — no submit/upload',
            'others' => 'No action required',
        ],
        'committee_review' => [
            'fyp_committee' => 'Keep existing evaluators, assign evaluators, or send back to students',
            'student_leader' => 'Wait — no action required',
            'supervisor' => 'No action required',
        ],
        'evaluators_assigned' => [
            'fyp_committee' => 'Confirm evaluator assignment if needed',
            'others' => 'Wait for evaluator review to open',
        ],
        'evaluator_review' => [
            'assigned_evaluators' => 'Submit accept / revision / reject review',
            'fyp_office_admin' => 'Can submit review on behalf of evaluators if permitted',
            'student_leader' => 'Wait',
        ],
        'supervisor_revision_pending' => [
            'supervisor' => 'Proceed after student resubmit or return to students',
            'student_leader' => 'Wait for supervisor',
        ],
        'revision_required' => [
            'student_leader' => 'Upload revised file and click Resubmit Deliverable',
            'others' => 'No action required',
        ],
        'committee_final' => [
            'fyp_committee' => 'Forward to Committee Head or return for revision',
            'others' => 'Wait',
        ],
        'committee_head_approval' => [
            'fyp_committee_head' => 'Approve phase or return for revision',
            'others' => 'Wait',
        ],
        'approved' => [
            'everyone' => 'Phase deliverable approved — no further action until session admin advances session',
        ],
        default => [
            'note' => 'Stage: '.$workflowStage,
        ],
    };
}
