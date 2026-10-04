<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\StudentTransferRequest;
use App\Models\User;
use App\Support\FypProposal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentTransferService
{
    public function __construct(
        private ProposalWorkflowService $workflow,
        private ProgramScopeService $programScope,
    ) {}

    public function activeRequestFor(User $student): ?StudentTransferRequest
    {
        return StudentTransferRequest::query()
            ->where('student_id', $student->id)
            ->whereIn('status', StudentTransferRequest::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * Other active projects in the same phase as the student's current project —
     * the only legal transfer destinations, so a student can't skip or regress a phase.
     * Only meaningful once the student's transfer request has been approved by the
     * FYP office (status "eligible") — otherwise there is nothing to browse yet.
     */
    public function transferTargetsFor(User $student): Collection
    {
        $project = $student->memberProject;

        if (! $project || (int) $project->student_id === (int) $student->id) {
            return collect();
        }

        $activeRequest = $this->activeRequestFor($student);

        if (! $activeRequest || ! in_array($activeRequest->status, ['eligible', 'pending_leader'], true)) {
            return collect();
        }

        return Project::query()
            ->where('id', '!=', $project->id)
            ->where('status', 'active')
            ->where('current_phase', $project->current_phase)
            ->with('student:id,name')
            ->withCount(['members as active_member_count' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->map(fn (Project $target) => [
                'id' => $target->id,
                'title' => $target->title,
                'current_phase' => $target->current_phase,
                'leader_name' => $target->student?->name,
                'active_member_count' => $target->active_member_count,
                'max_members' => FypProposal::maxMembers(),
            ]);
    }

    /**
     * Stage 1: the student asks permission to look for a new group. No target is chosen
     * yet — that only becomes possible once the FYP office approves this request.
     */
    public function requestTransfer(User $student, ?string $reason): StudentTransferRequest
    {
        $fromProject = $student->memberProject;

        if (! $fromProject) {
            throw ValidationException::withMessages([
                'project' => ['You are not an active member of a project team.'],
            ]);
        }

        if ((int) $fromProject->student_id === (int) $student->id) {
            throw ValidationException::withMessages([
                'project' => ['The group leader cannot request a transfer. Use supervisor change or team management instead.'],
            ]);
        }

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['Please provide a reason for the transfer request.'],
            ]);
        }

        if ($this->activeRequestFor($student)) {
            throw ValidationException::withMessages([
                'project' => ['You already have an active transfer request.'],
            ]);
        }

        return DB::transaction(function () use ($student, $fromProject, $reason) {
            $request = StudentTransferRequest::create([
                'student_id' => $student->id,
                'from_project_id' => $fromProject->id,
                'to_project_id' => null,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            $this->workflow->log(
                $fromProject,
                'group_confirmed',
                "{$student->name} requested permission to transfer to a different group",
                $student,
                'student',
                $reason
            );

            NotificationService::send(
                $fromProject->student_id,
                'Team Member Requested Transfer',
                "{$student->name} has requested permission to transfer out of your group.",
                'warning',
                ['project_id' => $fromProject->id]
            );

            $this->notifyAuthority(
                $fromProject,
                'New Student Transfer Request',
                "{$student->name} requested permission to transfer out of \"{$fromProject->title}\"."
            );

            return $this->loadRequest($request);
        });
    }

    /**
     * Stage 2: once approved, the student picks one of the eligible same-phase groups
     * and asks to join it. That group's leader (or an authority acting on their behalf)
     * then decides. Can be called again after a leader rejection to try another group.
     */
    public function selectTarget(User $student, StudentTransferRequest $request, int $toProjectId): StudentTransferRequest
    {
        if ((int) $request->student_id !== (int) $student->id) {
            throw ValidationException::withMessages([
                'request' => ['This is not your transfer request.'],
            ]);
        }

        if ($request->status !== 'eligible') {
            throw ValidationException::withMessages([
                'request' => ['You cannot choose a group at this stage of your transfer request.'],
            ]);
        }

        $fromProject = $request->fromProject;

        if ((int) $toProjectId === (int) $fromProject->id) {
            throw ValidationException::withMessages([
                'to_project_id' => ['Select a project different from your current one.'],
            ]);
        }

        $toProject = Project::find($toProjectId);

        if (! $toProject || $toProject->status !== 'active') {
            throw ValidationException::withMessages([
                'to_project_id' => ['Selected project could not be found.'],
            ]);
        }

        if ($toProject->current_phase !== $fromProject->current_phase) {
            throw ValidationException::withMessages([
                'to_project_id' => ['You can only request to join a project in the same phase as your current one.'],
            ]);
        }

        $activeCount = $toProject->members()->where('status', 'active')->count();
        if ($activeCount >= FypProposal::maxMembers()) {
            throw ValidationException::withMessages([
                'to_project_id' => ['The selected project already has the maximum number of members.'],
            ]);
        }

        return DB::transaction(function () use ($student, $request, $fromProject, $toProject) {
            $request->update([
                'to_project_id' => $toProject->id,
                'status' => 'pending_leader',
                'decided_by' => null,
                'decision_comments' => null,
                'decided_at' => null,
            ]);

            $this->workflow->log(
                $fromProject,
                'group_confirmed',
                "{$student->name} requested to join \"{$toProject->title}\"",
                $student,
                'student'
            );

            NotificationService::send(
                $toProject->student_id,
                'Request to Join Your Group',
                "{$student->name} has requested to join your group. You can accept or decline this request.",
                'info',
                ['project_id' => $toProject->id]
            );

            $this->notifyAuthority(
                $toProject,
                'Student Requested to Join a Group',
                "{$student->name} requested to join \"{$toProject->title}\". You can decide on the leader's behalf if needed."
            );

            return $this->loadRequest($request->fresh());
        });
    }

    public function cancel(User $actor, StudentTransferRequest $request): StudentTransferRequest
    {
        $isRequester = (int) $request->student_id === (int) $actor->id;

        if (! $isRequester && ! FypProposal::canDecideStudentTransfer($actor)) {
            throw ValidationException::withMessages([
                'request' => ['You are not authorized to cancel this transfer request.'],
            ]);
        }

        if (! $request->isActive()) {
            throw ValidationException::withMessages([
                'request' => ['Only an active transfer request can be cancelled.'],
            ]);
        }

        $request->update(['status' => 'cancelled']);

        return $this->loadRequest($request->fresh());
    }

    /**
     * Single decision entrypoint — dispatches to the FYP-office decision (stage 1) or the
     * target group leader's decision (stage 2) based on the request's current status, each
     * with its own authorization rule.
     */
    public function decide(User $actor, StudentTransferRequest $request, bool $approve, ?string $comments = null): StudentTransferRequest
    {
        if (! $request->isActive()) {
            throw ValidationException::withMessages([
                'request' => ['This request has already been decided.'],
            ]);
        }

        return match ($request->status) {
            'pending' => $this->decideAdmin($actor, $request, $approve, $comments),
            'pending_leader' => $this->decideLeader($actor, $request, $approve, $comments),
            default => throw ValidationException::withMessages([
                'request' => ['This request cannot be decided in its current state.'],
            ]),
        };
    }

    /**
     * Stage 1 decision: FYP office/admin/permission-holder grants or denies permission
     * to look for a new group at all.
     */
    protected function decideAdmin(User $actor, StudentTransferRequest $request, bool $approve, ?string $comments = null): StudentTransferRequest
    {
        if (! FypProposal::canDecideStudentTransfer($actor)) {
            throw ValidationException::withMessages([
                'request' => ['You are not authorized to decide student transfer requests.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $approve, $comments) {
            $student = $request->student;
            $fromProject = $request->fromProject;

            $request->update([
                'status' => $approve ? 'eligible' : 'rejected',
                'decided_by' => $actor->id,
                'decision_comments' => $comments,
                'decided_at' => now(),
            ]);

            if ($approve) {
                NotificationService::send(
                    $student->id,
                    'Transfer Request Approved',
                    'Your transfer request was approved. You can now browse eligible groups and request to join one.',
                    'success',
                    ['project_id' => $fromProject->id]
                );
            } else {
                NotificationService::send(
                    $student->id,
                    'Transfer Request Declined',
                    $comments ? "Your transfer request was declined. Reason: {$comments}" : 'Your transfer request was declined.',
                    'danger',
                    ['project_id' => $fromProject->id]
                );
            }

            return $this->loadRequest($request->fresh());
        });
    }

    /**
     * Stage 2 decision: the target group's leader (or the project's supervisor, or
     * admin/committee-head/permission-holder acting on the leader's behalf) accepts or
     * declines the join request. A decline loops the student back to "eligible" so they
     * can request a different group instead of restarting the whole process.
     */
    protected function decideLeader(User $actor, StudentTransferRequest $request, bool $approve, ?string $comments = null): StudentTransferRequest
    {
        $toProject = $request->toProject;

        if (! $toProject || ! $this->canDecideAsLeader($actor, $toProject)) {
            throw ValidationException::withMessages([
                'request' => ['You are not authorized to decide this join request.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $approve, $comments) {
            $student = $request->student;
            $fromProject = $request->fromProject;
            $toProject = $request->toProject;
            $actorRole = $actor->roles->first()?->name ?? 'admin';

            if ($approve) {
                $activeCount = $toProject->members()->where('status', 'active')->count();
                if ($activeCount >= FypProposal::maxMembers()) {
                    throw ValidationException::withMessages([
                        'request' => ['The destination project no longer has an open slot.'],
                    ]);
                }

                ProjectMember::where('project_id', $fromProject->id)
                    ->where('user_id', $student->id)
                    ->where('status', 'active')
                    ->update(['status' => 'removed']);

                ProjectMember::updateOrCreate(
                    ['project_id' => $toProject->id, 'user_id' => $student->id],
                    ['role' => 'member', 'status' => 'active']
                );

                $request->update([
                    'status' => 'approved',
                    'decided_by' => $actor->id,
                    'decision_comments' => $comments,
                    'decided_at' => now(),
                ]);

                $this->workflow->log($fromProject, 'group_confirmed', "{$student->name} was transferred out to \"{$toProject->title}\"", $actor, $actorRole, $comments);
                $this->workflow->log($toProject, 'group_confirmed', "{$student->name} was transferred in from \"{$fromProject->title}\"", $actor, $actorRole, $comments);

                NotificationService::send($student->id, 'Transfer Approved', "Your transfer to \"{$toProject->title}\" has been approved.", 'success', ['project_id' => $toProject->id]);
                NotificationService::send($fromProject->student_id, 'Team Member Transferred', "{$student->name} has been transferred out of your group.", 'warning', ['project_id' => $fromProject->id]);
                NotificationService::send($toProject->student_id, 'New Team Member', "{$student->name} has joined your group via an approved transfer.", 'success', ['project_id' => $toProject->id]);
            } else {
                // Rejected by the group, not by the FYP office — the student stays
                // "eligible" so they can request to join a different group.
                $request->update([
                    'status' => 'eligible',
                    'to_project_id' => null,
                    'decided_by' => $actor->id,
                    'decision_comments' => $comments,
                    'decided_at' => now(),
                ]);

                $this->workflow->log($fromProject, 'group_confirmed', "{$student->name}'s request to join \"{$toProject->title}\" was declined", $actor, $actorRole, $comments);

                NotificationService::send(
                    $student->id,
                    'Join Request Declined',
                    $comments
                        ? "Your request to join \"{$toProject->title}\" was declined. Reason: {$comments}. You can request to join another group."
                        : "Your request to join \"{$toProject->title}\" was declined. You can request to join another group.",
                    'danger',
                    ['project_id' => $fromProject->id]
                );
            }

            return $this->loadRequest($request->fresh());
        });
    }

    /**
     * Whether the actor can decide a join request on behalf of the target project —
     * its own student leader, its supervisor, or admin/committee-head/permission-holder.
     */
    protected function canDecideAsLeader(User $actor, Project $toProject): bool
    {
        return (int) $toProject->student_id === (int) $actor->id
            || FypProposal::canManageProjectTeamFor($actor, $toProject);
    }

    public function listActiveRequestsForUser(User $actor)
    {
        $query = StudentTransferRequest::query()
            ->whereIn('status', StudentTransferRequest::ACTIVE_STATUSES)
            ->with(['student:id,name', 'fromProject:id,title,program_id', 'toProject:id,title,program_id'])
            ->latest('id');

        $accessible = $this->programScope->accessibleProgramIds($actor);

        if ($accessible === []) {
            return collect();
        }

        if ($accessible !== null) {
            $query->whereHas('fromProject', fn ($q) => $q->whereIn('program_id', $accessible));
        }

        return $query->get();
    }

    /**
     * Of the requests an authority can see, only "pending" (stage 1) and "pending_leader"
     * (stage 2, decidable on the leader's behalf) actually need their decision right now —
     * "eligible" requests are waiting on the student to pick a group, not on staff.
     */
    public function decidableCountForUser(User $actor): int
    {
        return $this->listActiveRequestsForUser($actor)
            ->whereIn('status', ['pending', 'pending_leader'])
            ->count();
    }

    /**
     * Join requests awaiting this specific project's leader/supervisor decision.
     */
    public function pendingLeaderDecisionsForProject(Project $project): Collection
    {
        return StudentTransferRequest::query()
            ->where('to_project_id', $project->id)
            ->where('status', 'pending_leader')
            ->with(['student', 'fromProject'])
            ->latest('id')
            ->get();
    }

    /**
     * All join requests awaiting a decision across every project this supervisor supervises.
     */
    public function pendingLeaderDecisionsForSupervisor(User $supervisor): Collection
    {
        return StudentTransferRequest::query()
            ->where('status', 'pending_leader')
            ->whereHas('toProject', fn ($q) => $q->where('supervisor_id', $supervisor->id))
            ->with(['student', 'fromProject', 'toProject'])
            ->latest('id')
            ->get();
    }

    public function loadRequest(StudentTransferRequest $request): StudentTransferRequest
    {
        return $request->load(['student', 'fromProject', 'toProject', 'decidedBy']);
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
}
