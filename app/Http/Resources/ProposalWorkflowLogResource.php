<?php

namespace App\Http\Resources;

use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProposalWorkflowLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Evaluator and supervisor identities in this timeline are only visible to
        // admin/committee roles — students and supervisors see the role label
        // (e.g. "Evaluator", "Supervisor") instead of a name, so review feedback
        // stays anonymous the same way evaluator identities already are.
        $hideActor = ! FypProposal::canViewEvaluatorNames($request->user())
            && in_array($this->actor_role, ['evaluator', 'supervisor'], true);

        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'stage_label' => FypProposal::stageLabel($this->stage),
            'action' => $this->action,
            'action_label' => $this->humanActionLabel(),
            'is_sensitive' => $this->isSensitiveAction(),
            'actor_role' => $this->actor_role,
            'comments' => $this->comments,
            'actor' => $hideActor
                ? null
                : new UserResource($this->whenLoaded('actor')),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    protected function humanActionLabel(): string
    {
        $labels = [
            'assign_evaluators' => 'Evaluators assigned',
            'evaluator_review' => 'Evaluator review submitted',
            'committee_final' => 'Committee final review recorded',
            'committee_head_approve' => 'Committee head approval',
            'return_to_team_formation' => 'Returned to team formation',
            'supervisor_change' => 'Supervisor changed',
            'delete_project' => 'Project deleted',
            'accept_on_behalf' => 'Invitation accepted on behalf',
        ];

        return $labels[$this->action] ?? ucwords(str_replace('_', ' ', (string) $this->action));
    }

    protected function isSensitiveAction(): bool
    {
        return in_array($this->action, [
            'assign_evaluators',
            'evaluator_review',
            'committee_final',
            'committee_head_approve',
            'return_to_team_formation',
            'supervisor_change',
            'delete_project',
            'accept_on_behalf',
            'assign_evaluators_on_behalf',
        ], true);
    }
}
