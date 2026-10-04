<?php

namespace App\Http\Resources;

use App\Models\ProposalSession;
use App\Services\ProposalSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProposalSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(ProposalSessionService::class);
        $session = $service->syncSubmissionOpenState($this->resource);
        $countdown = $service->countdownState($session);

        return [
            'id' => $session->id,
            'program_id' => $session->program_id,
            'program_name' => $this->whenLoaded('program', fn () => $this->program?->name),
            'department_name' => $this->whenLoaded('program', fn () => $this->program?->department?->name),
            'name' => $session->name,
            'code' => $session->code,
            'is_submission_open' => $session->is_submission_open,
            'is_fully_locked' => $session->is_fully_locked,
            'status' => $session->status,
            'lifecycle_phase' => $session->lifecycle_phase,
            'lifecycle_phase_label' => $service->lifecycleLabel($session->lifecycle_phase ?? ProposalSession::LIFECYCLE_PROPOSAL_PHASE),
            'is_current_proposal_session' => $service->isCurrentProposalSession($session),
            'initial_draft_deadline' => $session->initial_draft_deadline?->toIso8601String(),
            'final_lock_deadline' => $session->final_lock_deadline?->toIso8601String(),
            'phase_1_initial_deadline' => $session->phase_1_initial_deadline?->toIso8601String(),
            'phase_1_final_lock_deadline' => $session->phase_1_final_lock_deadline?->toIso8601String(),
            'phase_1_completed_at' => $session->phase_1_completed_at?->toIso8601String(),
            'phase_2_initial_deadline' => $session->phase_2_initial_deadline?->toIso8601String(),
            'phase_2_final_lock_deadline' => $session->phase_2_final_lock_deadline?->toIso8601String(),
            'phase_2_completed_at' => $session->phase_2_completed_at?->toIso8601String(),
            'can_manually_close_submissions' => $service->canManuallyCloseSubmissions($session),
            'initial_deadline_pending' => $service->isInitialDeadlinePending($session),
            'notes' => $this->notes,
            'projects_count' => $this->whenCounted('projects'),
            'students_count' => $this->students_count ?? null,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'countdown_phase' => $countdown['phase'],
            'countdown_label' => $countdown['label'],
            'countdown_target' => $countdown['target']?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
