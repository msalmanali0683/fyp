<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SupervisorChangeRequest;
use App\Models\User;
use App\Support\FypProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupervisorChangeService
{
    public function __construct(
        private ProposalWorkflowService $workflow,
        private SupervisorCapacityService $supervisorCapacity,
        private ProgramScopeService $programScope,
        private ProposalSessionService $sessionService,
    ) {}

    public function activeRequest(Project $project): ?SupervisorChangeRequest
    {
        return $project->supervisorChangeRequests()
            ->whereIn('overall_status', SupervisorChangeRequest::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * All active supervisor-change requests visible to this actor, scoped to the
     * programs they can access. Used for the admin/committee "inbox" list so a
     * pending request is discoverable without hunting through individual projects.
     */
    public function listActiveRequestsForUser(User $actor)
    {
        $query = SupervisorChangeRequest::query()
            ->whereIn('overall_status', SupervisorChangeRequest::ACTIVE_STATUSES)
            ->with(['project:id,title,program_id', 'requestedBy:id,name', 'currentSupervisor:id,name', 'newSupervisor:id,name'])
            ->latest('id');

        $accessible = $this->programScope->accessibleProgramIds($actor);

        if ($accessible === []) {
            return collect();
        }

        if ($accessible !== null) {
            $query->whereHas('project', fn ($q) => $q->whereIn('program_id', $accessible));
        }

        return $query->get();
    }

    public function requestSupervisorChange(User $student, Project $project, int $newSupervisorId, ?string $reason): SupervisorChangeRequest
    {
        if ((int) $project->student_id !== (int) $student->id) {
            throw ValidationException::withMessages([
                'project' => ['Only the group leader can request a supervisor change.'],
            ]);
        }

        $this->sessionService->assertCanEditProject($student, $project);

        if (! $project->supervisor_id) {
            throw ValidationException::withMessages([
                'project' => ['This project does not have a current supervisor to change.'],
            ]);
        }

        if ($project->supervisor_status !== 'accepted') {
            throw ValidationException::withMessages([
                'project' => ['A supervisor change can only be requested after the current supervisor has accepted supervision.'],
            ]);
        }

        if (in_array($project->workflow_stage, ['draft', 'invitations_pending'], true)) {
            throw ValidationException::withMessages([
                'project' => ['A supervisor change cannot be requested before a supervisor is confirmed.'],
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['Please provide a reason for the supervisor change request.'],
            ]);
        }

        if ($this->activeRequest($project)) {
            throw ValidationException::withMessages([
                'project' => ['There is already an active supervisor change request for this project.'],
            ]);
        }

        if ((int) $newSupervisorId === (int) $project->supervisor_id) {
            throw ValidationException::withMessages([
                'new_supervisor_id' => ['Please select a supervisor different from the current one.'],
            ]);
        }

        $newSupervisor = User::role('supervisor')->find($newSupervisorId);
        if (! $newSupervisor) {
            throw ValidationException::withMessages([
                'new_supervisor_id' => ['Please select a valid supervisor.'],
            ]);
        }

        if ($project->program_id && ! $this->userBelongsToProgram($newSupervisor, (int) $project->program_id)) {
            throw ValidationException::withMessages([
                'new_supervisor_id' => ['The selected supervisor is not assigned to this project\'s program.'],
            ]);
        }

        $phase = $project->current_phase ?: 'proposal';
        if (! $this->supervisorCapacity->hasCapacity($newSupervisor, $phase, $project->id)) {
            throw ValidationException::withMessages([
                'new_supervisor_id' => ['This supervisor has reached the maximum number of groups they can supervise.'],
            ]);
        }

        if ($project->evaluators()->where('evaluator_id', $newSupervisor->id)->exists()) {
            throw ValidationException::withMessages([
                'new_supervisor_id' => [FypProposal::supervisorEvaluatorConflictMessage()],
            ]);
        }

        return DB::transaction(function () use ($student, $project, $newSupervisor, $reason) {
            $currentSupervisor = $project->supervisor;

            $request = SupervisorChangeRequest::create([
                'project_id' => $project->id,
                'requested_by' => $student->id,
                'current_supervisor_id' => $project->supervisor_id,
                'new_supervisor_id' => $newSupervisor->id,
                'reason' => $reason,
                'current_supervisor_status' => 'pending',
                'new_supervisor_status' => 'pending',
                'authority_status' => 'pending',
                'overall_status' => 'pending',
            ]);

            $this->workflow->log(
                $project,
                'supervisor_change',
                'Supervisor change requested by group leader: '
                    .($currentSupervisor?->name ?? 'current supervisor').' → '.$newSupervisor->name,
                $student,
                'student',
                $reason
            );

            if ($currentSupervisor) {
                NotificationService::send(
                    $currentSupervisor->id,
                    'Supervisor Change Request',
                    "The group for \"{$project->title}\" has requested to change their supervisor. Please review and respond.",
                    'warning',
                    ['project_id' => $project->id, 'type' => 'supervisor_change_request']
                );
            }

            NotificationService::send(
                $newSupervisor->id,
                'Supervisor Change Request',
                "You have been requested as the new supervisor for \"{$project->title}\". Please review and respond.",
                'info',
                ['project_id' => $project->id, 'type' => 'supervisor_change_request']
            );

            $this->notifyGroup(
                $project,
                'Supervisor Change Requested',
                "A request to change the supervisor to {$newSupervisor->name} has been submitted and is awaiting both supervisors' responses.",
                'info'
            );

            $this->notifyAuthority(
                $project,
                'New Supervisor Change Request',
                "\"{$project->title}\" has requested a supervisor change: "
                    .($currentSupervisor?->name ?? 'current supervisor')." → {$newSupervisor->name}."
            );

            return $this->loadRequest($request);
        });
    }

    public function respondToSupervisorChange(
        User $actor,
        SupervisorChangeRequest $request,
        ?string $which,
        bool $accept,
        ?string $comments = null
    ): SupervisorChangeRequest {
        if ($request->overall_status !== 'pending') {
            throw ValidationException::withMessages([
                'request' => ['This supervisor change request is no longer awaiting supervisor responses.'],
            ]);
        }

        $isCurrentSupervisor = (int) $request->current_supervisor_id === (int) $actor->id;
        $isNewSupervisor = (int) $request->new_supervisor_id === (int) $actor->id;
        $isOfficeActor = FypProposal::canManageSupervisorInvitations($actor);

        $side = $which;
        if (! $side) {
            if ($isCurrentSupervisor) {
                $side = 'current';
            } elseif ($isNewSupervisor) {
                $side = 'new';
            }
        }

        if (! in_array($side, ['current', 'new'], true)) {
            throw ValidationException::withMessages([
                'which' => ['Specify which supervisor you are responding on behalf of.'],
            ]);
        }

        $sideSupervisorId = $side === 'current' ? $request->current_supervisor_id : $request->new_supervisor_id;
        $isSelf = (int) $sideSupervisorId === (int) $actor->id;

        if (! $isSelf && ! $isOfficeActor) {
            throw ValidationException::withMessages([
                'request' => ['You are not authorized to respond to this supervisor change request.'],
            ]);
        }

        $statusField = $side === 'current' ? 'current_supervisor_status' : 'new_supervisor_status';
        $commentsField = $side === 'current' ? 'current_supervisor_comments' : 'new_supervisor_comments';
        $responderField = $side === 'current' ? 'current_supervisor_responded_by' : 'new_supervisor_responded_by';

        if ($request->{$statusField} !== 'pending') {
            throw ValidationException::withMessages([
                'request' => ['This supervisor has already responded to the change request.'],
            ]);
        }

        $onBehalf = ! $isSelf && $isOfficeActor;

        return DB::transaction(function () use (
            $actor, $request, $side, $accept, $comments, $statusField, $commentsField, $responderField, $onBehalf
        ) {
            $project = $request->project;
            $status = $accept ? 'accepted' : 'rejected';
            $sideSupervisor = $side === 'current' ? $request->currentSupervisor : $request->newSupervisor;
            $sideLabel = $side === 'current' ? 'current supervisor' : 'new supervisor';

            $request->update([
                $statusField => $status,
                $commentsField => $comments,
                $responderField => $actor->id,
            ]);

            $actorRole = $onBehalf ? ($actor->roles->first()?->name ?? 'admin') : 'supervisor';
            $decisionWord = $accept ? 'accepted' : 'rejected';

            $this->workflow->log(
                $project,
                'supervisor_change',
                $onBehalf
                    ? "Supervisor change {$decisionWord} by FYP office on behalf of the {$sideLabel} ({$sideSupervisor?->name})"
                    : ucfirst($sideLabel)." {$decisionWord} the supervisor change request",
                $actor,
                $actorRole,
                $comments
            );

            $this->notifyGroup(
                $project,
                'Supervisor Change Update',
                "The {$sideLabel}".($sideSupervisor ? " ({$sideSupervisor->name})" : '')." has {$decisionWord} the supervisor change request.",
                $accept ? 'info' : 'warning'
            );

            if ($onBehalf && $sideSupervisor) {
                NotificationService::send(
                    $sideSupervisor->id,
                    'Supervisor Change Response Recorded',
                    "The FYP office {$decisionWord} the supervisor change request for \"{$project->title}\" on your behalf.",
                    'info',
                    ['project_id' => $project->id]
                );
            }

            $request->refresh();

            if ($request->current_supervisor_status !== 'pending' && $request->new_supervisor_status !== 'pending') {
                $request->update(['overall_status' => 'awaiting_authority']);

                $this->workflow->log(
                    $project,
                    'supervisor_change',
                    'Both supervisors have responded. Awaiting authority decision.',
                    null,
                    'system'
                );

                $this->notifyAuthority(
                    $project,
                    'Supervisor Change Decision Required',
                    "Both supervisors have responded to the supervisor change request for \"{$project->title}\". A final decision is required."
                );
            }

            return $this->loadRequest($request->fresh());
        });
    }

    public function authorityDecide(
        User $actor,
        SupervisorChangeRequest $request,
        bool $approve,
        ?string $comments = null
    ): SupervisorChangeRequest {
        if (! FypProposal::canDecideSupervisorChange($actor)) {
            throw ValidationException::withMessages([
                'request' => ['You are not authorized to decide supervisor change requests.'],
            ]);
        }

        if ($request->overall_status !== 'awaiting_authority') {
            throw ValidationException::withMessages([
                'request' => ['This request is not awaiting an authority decision.'],
            ]);
        }

        $project = $request->project;
        $newSupervisor = $request->newSupervisor;
        $actorRole = $actor->roles->first()?->name ?? 'admin';

        return DB::transaction(function () use ($actor, $request, $approve, $comments, $project, $newSupervisor, $actorRole) {
            if ($approve) {
                if (! $newSupervisor) {
                    throw ValidationException::withMessages([
                        'request' => ['The requested new supervisor is no longer available.'],
                    ]);
                }

                $phase = $project->current_phase ?: 'proposal';
                if (! $this->supervisorCapacity->hasCapacity($newSupervisor, $phase, $project->id)) {
                    throw ValidationException::withMessages([
                        'request' => ['The selected new supervisor no longer has capacity to take this group.'],
                    ]);
                }

                if ($project->evaluators()->where('evaluator_id', $newSupervisor->id)->exists()) {
                    throw ValidationException::withMessages([
                        'request' => [FypProposal::supervisorEvaluatorConflictMessage()],
                    ]);
                }

                $project->update([
                    'supervisor_id' => $newSupervisor->id,
                    'supervisor_status' => 'accepted',
                    'supervisor_rejection_feedback' => null,
                ]);

                $request->update([
                    'authority_status' => 'approved',
                    'authority_comments' => $comments,
                    'authority_decided_by' => $actor->id,
                    'overall_status' => 'approved',
                ]);

                $this->workflow->log(
                    $project,
                    'supervisor_change',
                    "Supervisor change approved. New supervisor: {$newSupervisor->name}",
                    $actor,
                    $actorRole,
                    $comments
                );

                $this->notifyGroup(
                    $project,
                    'Supervisor Change Approved',
                    "Your supervisor change request was approved. {$newSupervisor->name} is now your supervisor.",
                    'success'
                );

                NotificationService::send(
                    $newSupervisor->id,
                    'Supervision Confirmed',
                    "You have been confirmed as the supervisor for \"{$project->title}\".",
                    'success',
                    ['project_id' => $project->id]
                );

                if ($request->current_supervisor_id) {
                    NotificationService::send(
                        $request->current_supervisor_id,
                        'Supervision Reassigned',
                        "The supervision of \"{$project->title}\" has been reassigned to {$newSupervisor->name}.",
                        'info',
                        ['project_id' => $project->id]
                    );
                }
            } else {
                $request->update([
                    'authority_status' => 'declined',
                    'authority_comments' => $comments,
                    'authority_decided_by' => $actor->id,
                    'overall_status' => 'declined',
                ]);

                $this->workflow->log(
                    $project,
                    'supervisor_change',
                    'Supervisor change declined. The current supervisor remains unchanged.',
                    $actor,
                    $actorRole,
                    $comments
                );

                $this->notifyGroup(
                    $project,
                    'Supervisor Change Declined',
                    'Your supervisor change request was declined. Your current supervisor remains unchanged.',
                    'warning'
                );
            }

            return $this->loadRequest($request->fresh());
        });
    }

    public function cancelSupervisorChange(User $actor, SupervisorChangeRequest $request, ?string $comments = null): SupervisorChangeRequest
    {
        $project = $request->project;
        $isLeader = (int) $project->student_id === (int) $actor->id;

        if (! $isLeader && ! FypProposal::canDecideSupervisorChange($actor)) {
            throw ValidationException::withMessages([
                'request' => ['You are not authorized to cancel this supervisor change request.'],
            ]);
        }

        if (! $request->isActive()) {
            throw ValidationException::withMessages([
                'request' => ['Only an active supervisor change request can be cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $project, $comments, $isLeader) {
            $request->update(['overall_status' => 'cancelled']);

            $this->workflow->log(
                $project,
                'supervisor_change',
                $isLeader
                    ? 'Supervisor change request cancelled by group leader'
                    : 'Supervisor change request cancelled by FYP office',
                $actor,
                $isLeader ? 'student' : ($actor->roles->first()?->name ?? 'admin'),
                $comments
            );

            $this->notifyGroup(
                $project,
                'Supervisor Change Cancelled',
                'The supervisor change request has been cancelled.',
                'info'
            );

            return $this->loadRequest($request->fresh());
        });
    }

    public function loadRequest(SupervisorChangeRequest $request): SupervisorChangeRequest
    {
        return $request->load(['requestedBy', 'currentSupervisor', 'newSupervisor']);
    }

    protected function notifyGroup(Project $project, string $title, string $message, string $type = 'info'): void
    {
        $memberIds = $project->members()->where('status', 'active')->pluck('user_id')->all();
        NotificationService::sendToMany($memberIds, $title, $message, $type, ['project_id' => $project->id]);
    }

    protected function notifyAuthority(Project $project, string $title, string $message): void
    {
        $recipientIds = collect();

        if ($project->program_id) {
            $recipientIds = $recipientIds->merge(
                $this->programScope->programHeadIds((int) $project->program_id)
            );
        }

        $recipientIds = $recipientIds
            ->merge(User::role('admin')->pluck('id'))
            ->unique()
            ->values()
            ->all();

        NotificationService::sendToMany($recipientIds, $title, $message, 'info', ['project_id' => $project->id]);
    }

    protected function userBelongsToProgram(User $user, int $programId): bool
    {
        if ((int) $user->program_id === $programId) {
            return true;
        }

        return $user->programMemberships()->where('program_id', $programId)->exists();
    }
}
