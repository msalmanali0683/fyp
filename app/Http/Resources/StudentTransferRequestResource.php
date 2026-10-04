<?php

namespace App\Http\Resources;

use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentTransferRequestResource extends JsonResource
{
    protected const STATUS_LABELS = [
        'pending' => 'Awaiting FYP Office Approval',
        'eligible' => 'Approved — Choose a Group',
        'pending_leader' => 'Awaiting Group Leader Decision',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'student' => new UserResource($this->whenLoaded('student')),
            'from_project_id' => $this->from_project_id,
            'from_project' => $this->when($this->relationLoaded('fromProject') && $this->fromProject, fn () => [
                'id' => $this->fromProject->id,
                'title' => $this->fromProject->title,
            ]),
            'to_project_id' => $this->to_project_id,
            'to_project' => $this->when($this->relationLoaded('toProject') && $this->toProject, fn () => [
                'id' => $this->toProject->id,
                'title' => $this->toProject->title,
            ]),
            'reason' => $this->reason,
            'status' => $this->status,
            'status_label' => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'decision_comments' => $this->decision_comments,
            'decided_by' => new UserResource($this->whenLoaded('decidedBy')),
            'decided_at' => $this->decided_at?->toDateTimeString(),
            'viewer_can_decide' => $this->viewerCanDecide($user),
            'viewer_can_select_target' => $user
                && (int) $this->student_id === (int) $user->id
                && $this->status === 'eligible',
            'viewer_can_cancel' => $user
                && in_array($this->status, ['pending', 'eligible', 'pending_leader'], true)
                && ((int) $this->student_id === (int) $user->id || FypProposal::canDecideStudentTransfer($user)),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    protected function viewerCanDecide($user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->status === 'pending') {
            return FypProposal::canDecideStudentTransfer($user);
        }

        if ($this->status === 'pending_leader' && $this->relationLoaded('toProject') && $this->toProject) {
            return (int) $this->toProject->student_id === (int) $user->id
                || FypProposal::canManageProjectTeamFor($user, $this->toProject);
        }

        return false;
    }
}
