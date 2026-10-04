<?php

namespace App\Services;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectInvitation;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\ProposalSession;
use App\Models\ProposalWorkflowLog;
use App\Models\User;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposalWorkflowService
{
    public function __construct(
        private ProposalDocumentService $documents,
        private SupervisorCapacityService $supervisorCapacity,
        private EvaluatorCapacityService $evaluatorCapacity,
        private ProgramScopeService $programScope,
        private ProposalSessionService $sessionService,
        private QuestionBankService $questionBank,
    ) {}

    public function isEligibleForGroup(User $user): bool
    {
        return $this->registrationStatus($user)['can_register_proposal'];
    }

    public function registrationStatus(User $user): array
    {
        $sessionContext = $this->sessionService->studentPortalContext($user);

        if (! $user->hasRole('student') || $user->status !== 'active') {
            return [
                'can_register_proposal' => false,
                'is_proposal_enrolled' => (bool) $user->is_proposal_enrolled,
                'in_group' => false,
                'has_pending_invitations' => false,
                'block_reason' => 'Your account is not eligible to register a proposal.',
                'session' => $sessionContext,
            ];
        }

        if (! $user->is_proposal_enrolled) {
            return [
                'can_register_proposal' => false,
                'is_proposal_enrolled' => false,
                'in_group' => false,
                'has_pending_invitations' => $this->hasPendingInvitations($user),
                'block_reason' => 'not_enrolled',
                'session' => $sessionContext,
            ];
        }

        $inGroup = ProjectMember::query()
            ->activeMembership()
            ->where('user_id', $user->id)
            ->exists();

        if ($inGroup) {
            return [
                'can_register_proposal' => false,
                'is_proposal_enrolled' => true,
                'in_group' => true,
                'has_pending_invitations' => $this->hasPendingInvitations($user),
                'block_reason' => 'in_group',
                'session' => $sessionContext,
            ];
        }

        if (Project::query()->where('student_id', $user->id)->exists()) {
            return [
                'can_register_proposal' => false,
                'is_proposal_enrolled' => true,
                'in_group' => false,
                'has_pending_invitations' => $this->hasPendingInvitations($user),
                'block_reason' => 'already_leader',
                'session' => $sessionContext,
            ];
        }

        $canRegister = $sessionContext['can_submit_new'] ?? false;

        return [
            'can_register_proposal' => $canRegister,
            'is_proposal_enrolled' => true,
            'in_group' => false,
            'has_pending_invitations' => $this->hasPendingInvitations($user),
            'block_reason' => $canRegister ? null : ($sessionContext['block_reason'] ?? 'session_closed'),
            'session' => $sessionContext,
        ];
    }

    protected function hasPendingInvitations(User $user): bool
    {
        return ProjectInvitation::query()
            ->where('invitee_id', $user->id)
            ->where('status', 'pending')
            ->exists();
    }

    public function isInProjectGroup(User $user): bool
    {
        if (Project::where('student_id', $user->id)->exists()) {
            return true;
        }

        if (ProjectMember::query()->activeMembership()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if (ProjectInvitation::where('invitee_id', $user->id)->where('status', 'pending')->exists()) {
            return true;
        }

        return false;
    }

    public function isEligibleInvitee(User $user): bool
    {
        if (! $user->hasRole('student') || $user->status !== 'active') {
            return false;
        }

        return ! $this->isInProjectGroup($user);
    }

    public function submitProposal(User $leader, array $data): Project
    {
        if (! $this->isEligibleForGroup($leader)) {
            throw ValidationException::withMessages([
                'student' => ['You are not eligible to register a proposal group.'],
            ]);
        }

        if (! $leader->program_id) {
            throw ValidationException::withMessages([
                'program_id' => ['Your account is not assigned to a program. Contact the FYP office before submitting a proposal.'],
            ]);
        }

        $this->sessionService->assertCanSubmitNewProposal($leader);

        $invitees = collect($data['invitee_ids'] ?? [])->unique()->values();
        $memberCount = $invitees->count() + 1;

        if ($memberCount < FypProposal::minMembers() || $memberCount > FypProposal::maxMembers()) {
            throw ValidationException::withMessages([
                'invitee_ids' => ['Team size must be between '.FypProposal::minMembers().' and '.FypProposal::maxMembers().' members including you.'],
            ]);
        }

        if ($invitees->contains($leader->id)) {
            throw ValidationException::withMessages([
                'invitee_ids' => ['You cannot invite yourself.'],
            ]);
        }

        foreach ($invitees as $inviteeId) {
            $invitee = User::find($inviteeId);
            if (! $invitee || ! $this->isEligibleInvitee($invitee)) {
                throw ValidationException::withMessages([
                    'invitee_ids' => ['One or more invited students are not eligible. Only students who have not joined any group can be invited.'],
                ]);
            }

            if ($leader->department_id && (int) $invitee->department_id !== (int) $leader->department_id) {
                throw ValidationException::withMessages([
                    'invitee_ids' => ['Invited students must belong to the same department as the group leader.'],
                ]);
            }
        }

        $supervisor = User::role('supervisor')->find($data['supervisor_id'] ?? null);
        if (! $supervisor) {
            throw ValidationException::withMessages([
                'supervisor_id' => ['Please select a valid supervisor.'],
            ]);
        }

        if ($leader->program_id && ! $this->userBelongsToProgram($supervisor, (int) $leader->program_id)) {
            throw ValidationException::withMessages([
                'supervisor_id' => ['The selected supervisor is not assigned to your program.'],
            ]);
        }

        if (! $this->supervisorCapacity->hasCapacity($supervisor, 'proposal')) {
            throw ValidationException::withMessages([
                'supervisor_id' => ['This supervisor has reached the maximum number of proposal groups they can supervise.'],
            ]);
        }

        return DB::transaction(function () use ($leader, $data, $invitees, $supervisor) {
            Project::purgeTrashedForStudent($leader->id);

            ProjectInvitation::query()
                ->where('invitee_id', $leader->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'responded_at' => now(),
                    'response_comments' => 'Student registered as a new group leader.',
                ]);

            $activeSession = $this->sessionService->activeSessionForUser($leader);

            $project = Project::create([
                'student_id' => $leader->id,
                'program_id' => $leader->program_id,
                'proposal_session_id' => $activeSession?->id,
                'supervisor_id' => $supervisor->id,
                'supervisor_status' => 'pending',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'area_of_specialization' => $data['area_of_specialization'] ?? null,
                'academic_year' => $data['academic_year'] ?? now()->format('Y').'-'.(now()->year + 1),
                'current_phase' => 'proposal',
                'workflow_stage' => 'invitations_pending',
                'proposal_submitted_at' => now(),
                'status' => 'active',
            ]);

            foreach (FypPhases::slugs() as $phase) {
                ProjectPhase::create([
                    'project_id' => $project->id,
                    'phase' => $phase,
                    'status' => 'draft',
                    'content' => null,
                    'attachment' => null,
                ]);
            }

            $document = $this->documents->store($data['proposal_file'], $project->id);
            $project->phase('proposal')?->update([
                'attachment' => $document['path'],
                'content' => $document['original_name'],
            ]);

            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $leader->id,
                'role' => 'leader',
            ]);

            foreach ($invitees as $inviteeId) {
                ProjectInvitation::create([
                    'project_id' => $project->id,
                    'inviter_id' => $leader->id,
                    'invitee_id' => $inviteeId,
                    'status' => 'pending',
                ]);

                NotificationService::send(
                    $inviteeId,
                    'FYP Group Invitation',
                    "{$leader->name} invited you to join the proposal: {$project->title}",
                    'info',
                    ['project_id' => $project->id, 'type' => 'group_invitation']
                );
            }

            $this->log(
                $project,
                'proposal_submitted',
                'Proposal submitted and invitations sent',
                $leader,
                'student',
                $document['original_name'] ?? null
            );

            $leader->update(['is_proposal_enrolled' => true]);

            return $this->loadProject($project);
        });
    }

    public function inviteMembers(User $actor, Project $project, array $inviteeIds): Project
    {
        $isLeader = (int) $project->student_id === (int) $actor->id;

        if (! $isLeader && ! FypProposal::canManageProjectTeam($actor)) {
            throw ValidationException::withMessages(['project' => ['Unauthorized to send team invitations.']]);
        }

        // Reaching the minimum member count auto-advances the project past
        // invitations_pending (see checkGroupComplete()) even if a rejection
        // leaves an open slot the leader would still like to fill — e.g. the
        // team hits its minimum from one accepted invite right after another
        // invitee rejected. Team formation isn't truly "locked" until a
        // supervisor has actually confirmed, so the leader can keep inviting
        // up to the max through supervisor_pending/supervisor_rejected too.
        if (! in_array($project->workflow_stage, ['invitations_pending', 'supervisor_pending', 'supervisor_rejected'], true)) {
            throw ValidationException::withMessages(['project' => ['Invitations can only be sent while group formation is in progress.']]);
        }

        if ($isLeader) {
            $this->sessionService->assertCanEditProject($actor, $project);
        }

        $inviteeIds = collect($inviteeIds)->unique()->values();

        if ($inviteeIds->isEmpty()) {
            throw ValidationException::withMessages(['invitee_ids' => ['Select at least one student to invite.']]);
        }

        if ($inviteeIds->contains($project->student_id)) {
            throw ValidationException::withMessages(['invitee_ids' => ['The group leader cannot be invited as a member.']]);
        }

        $memberCount = $project->members()->where('status', 'active')->count();
        $pendingCount = $project->invitations()->where('status', 'pending')->count();
        $openSlots = FypProposal::maxMembers() - $memberCount - $pendingCount;

        if ($inviteeIds->count() > $openSlots) {
            throw ValidationException::withMessages([
                'invitee_ids' => ["You can invite at most {$openSlots} more student(s) for this group."],
            ]);
        }

        $leaderDepartmentId = $project->student?->department_id;

        foreach ($inviteeIds as $inviteeId) {
            $invitee = User::find($inviteeId);
            if (! $invitee || ! $this->isEligibleInvitee($invitee)) {
                throw ValidationException::withMessages([
                    'invitee_ids' => ['One or more students are not eligible. Only students who have not joined any group can be invited.'],
                ]);
            }

            if ($leaderDepartmentId && (int) $invitee->department_id !== (int) $leaderDepartmentId) {
                throw ValidationException::withMessages([
                    'invitee_ids' => ['Invited students must belong to the same department as the group leader.'],
                ]);
            }

            $existing = ProjectInvitation::query()
                ->where('project_id', $project->id)
                ->where('invitee_id', $inviteeId)
                ->first();

            if ($existing && in_array($existing->status, ['pending', 'accepted'], true)) {
                throw ValidationException::withMessages([
                    'invitee_ids' => ['One or more students already have a pending or accepted invitation for this group.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $isLeader, $project, $inviteeIds) {
            foreach ($inviteeIds as $inviteeId) {
                $existing = ProjectInvitation::query()
                    ->where('project_id', $project->id)
                    ->where('invitee_id', $inviteeId)
                    ->first();

                if ($existing && $existing->status === 'rejected') {
                    $existing->update([
                        'status' => 'pending',
                        'inviter_id' => $actor->id,
                        'responded_at' => null,
                        'response_comments' => null,
                    ]);
                } else {
                    ProjectInvitation::create([
                        'project_id' => $project->id,
                        'inviter_id' => $actor->id,
                        'invitee_id' => $inviteeId,
                        'status' => 'pending',
                    ]);
                }

                $inviterLabel = $isLeader ? $actor->name : 'The FYP office';
                NotificationService::send(
                    $inviteeId,
                    'FYP Group Invitation',
                    "{$inviterLabel} invited you to join the proposal: {$project->title}",
                    'info',
                    ['project_id' => $project->id, 'type' => 'group_invitation']
                );
            }

            // A fresh pending invite reopens team formation even if the project had
            // already advanced past it (e.g. the minimum was met and a supervisor
            // was invited) — the newly invited student's response should be
            // resolved before supervision review continues.
            if ($project->workflow_stage !== 'invitations_pending') {
                $project->update(['workflow_stage' => 'invitations_pending']);
            }

            $this->log(
                $project,
                'group_confirmed',
                $isLeader ? 'Group invitations sent by leader' : 'Group invitations sent by FYP office',
                $actor,
                $isLeader ? 'student' : ($actor->roles->first()?->name ?? 'admin')
            );

            return $this->loadProject($project->fresh());
        });
    }

    public function addTeamMembersDirectly(User $actor, Project $project, array $userIds, ?string $comments = null): Project
    {
        if (! FypProposal::canManageProjectTeamFor($actor, $project)) {
            throw ValidationException::withMessages(['project' => ['Unauthorized to add team members directly.']]);
        }

        if ($project->status !== 'active') {
            throw ValidationException::withMessages(['project' => ['This project is not active.']]);
        }

        $userIds = collect($userIds)->unique()->values();

        if ($userIds->isEmpty()) {
            throw ValidationException::withMessages(['user_ids' => ['Select at least one student to add.']]);
        }

        if ($userIds->contains($project->student_id)) {
            throw ValidationException::withMessages(['user_ids' => ['The group leader is already part of the team.']]);
        }

        $memberCount = $project->members()->where('status', 'active')->count();
        $openSlots = FypProposal::maxMembers() - $memberCount;

        if ($userIds->count() > $openSlots) {
            throw ValidationException::withMessages([
                'user_ids' => ["You can add at most {$openSlots} more member(s) to this group."],
            ]);
        }

        $transfers = [];

        foreach ($userIds as $userId) {
            $student = User::find($userId);

            if (! $student || ! $student->hasRole('student') || $student->status !== 'active') {
                throw ValidationException::withMessages([
                    'user_ids' => ['One or more students are not eligible.'],
                ]);
            }

            if ($project->members()->where('user_id', $userId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages([
                    'user_ids' => ['One or more students are already active members of this group.'],
                ]);
            }

            $currentProject = $student->studentProject();

            if ($currentProject) {
                if ((int) $currentProject->student_id === (int) $student->id) {
                    throw ValidationException::withMessages([
                        'user_ids' => ["{$student->name} is a group leader on another project and cannot be added this way."],
                    ]);
                }

                if ($currentProject->current_phase !== $project->current_phase) {
                    throw ValidationException::withMessages([
                        'user_ids' => ["{$student->name} is currently on a project in a different phase and cannot be transferred directly."],
                    ]);
                }

                $transfers[$userId] = $currentProject;
            }
        }

        return DB::transaction(function () use ($actor, $project, $userIds, $comments, $transfers) {
            $reason = $comments ?: 'Added directly by FYP office.';
            $actorRole = $actor->roles->first()?->name ?? 'admin';

            foreach ($userIds as $userId) {
                $student = User::find($userId);
                $sourceProject = $transfers[$userId] ?? null;

                if ($sourceProject) {
                    ProjectMember::where('project_id', $sourceProject->id)
                        ->where('user_id', $userId)
                        ->where('status', 'active')
                        ->update(['status' => 'removed']);

                    $this->log(
                        $sourceProject,
                        'group_confirmed',
                        "{$student?->name} was transferred out to \"{$project->title}\" by {$actorRole}",
                        $actor,
                        $actorRole,
                        $comments
                    );

                    NotificationService::send(
                        $sourceProject->student_id,
                        'Team Member Transferred',
                        "{$student?->name} was transferred to another group by the FYP office.",
                        'warning',
                        ['project_id' => $sourceProject->id]
                    );
                }

                ProjectInvitation::updateOrCreate(
                    [
                        'project_id' => $project->id,
                        'invitee_id' => $userId,
                    ],
                    [
                        'inviter_id' => $actor->id,
                        'status' => 'accepted',
                        'responded_at' => now(),
                        'response_comments' => $reason,
                    ]
                );

                ProjectMember::updateOrCreate(
                    ['project_id' => $project->id, 'user_id' => $userId],
                    ['role' => 'member', 'status' => 'active']
                );

                $student?->update(['is_proposal_enrolled' => true]);

                NotificationService::send(
                    $userId,
                    $sourceProject ? 'Transferred to New Group' : 'Added to FYP Group',
                    $sourceProject
                        ? "You were transferred to the proposal \"{$project->title}\" by the FYP office. {$reason}"
                        : "You were added to the proposal \"{$project->title}\" by the FYP office. {$reason}",
                    'info',
                    ['project_id' => $project->id]
                );

                $this->log(
                    $project,
                    'group_confirmed',
                    $sourceProject
                        ? "{$student?->name} was transferred into the group by {$actorRole}"
                        : "{$student?->name} was added to the group by {$actorRole}",
                    $actor,
                    $actorRole,
                    $comments
                );
            }

            NotificationService::send(
                $project->student_id,
                'Team Member Added',
                $userIds->count() === 1
                    ? 'A student was added to your group by the FYP office.'
                    : "{$userIds->count()} students were added to your group by the FYP office.",
                'info',
                ['project_id' => $project->id]
            );

            if ($project->current_phase === 'proposal') {
                $this->checkGroupComplete($project->fresh());
            }

            return $this->loadProject($project->fresh());
        });
    }

    public function cancelInvitation(User $actor, Project $project, ProjectInvitation $invitation, ?string $comments = null): Project
    {
        if ($invitation->project_id !== $project->id) {
            throw ValidationException::withMessages(['invitation' => ['Invitation not found for this project.']]);
        }

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages(['invitation' => ['Only pending invitations can be cancelled.']]);
        }

        if (! FypProposal::canCancelInvitation($actor, $project)) {
            throw ValidationException::withMessages(['invitation' => ['Unauthorized to cancel invitations.']]);
        }

        return DB::transaction(function () use ($actor, $project, $invitation, $comments) {
            $inviteeName = $invitation->invitee?->name ?? 'Student';
            $reason = $comments ?: 'Invitation cancelled by administrator.';

            $invitation->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'response_comments' => $reason,
            ]);

            $actorLabel = (int) $project->student_id === (int) $actor->id
                ? 'student'
                : ($actor->roles->first()?->name ?? 'admin');

            $this->log(
                $project,
                'group_confirmed',
                "Invitation to {$inviteeName} was cancelled",
                $actor,
                $actorLabel,
                $comments
            );

            NotificationService::send(
                $invitation->invitee_id,
                'Group Invitation Cancelled',
                "Your invitation to join \"{$project->title}\" was cancelled. {$reason}",
                'warning',
                ['project_id' => $project->id]
            );

            if ((int) $project->student_id !== (int) $actor->id) {
                NotificationService::send(
                    $project->student_id,
                    'Group Invitation Cancelled',
                    "The invitation to {$inviteeName} was cancelled by the FYP office.".($comments ? " Reason: {$comments}" : ''),
                    'info',
                    ['project_id' => $project->id]
                );
            }

            $project->update(['workflow_stage' => 'invitations_pending']);

            return $this->loadProject($project->fresh());
        });
    }

    public function respondInvitation(User $user, ProjectInvitation $invitation, string $response, ?string $comments = null): Project
    {
        if ($invitation->invitee_id !== $user->id) {
            throw ValidationException::withMessages(['invitation' => ['Unauthorized.']]);
        }

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages(['invitation' => ['This invitation has already been responded to.']]);
        }

        if ($response === 'rejected' && blank($comments)) {
            throw ValidationException::withMessages(['comments' => ['Please provide a reason for rejecting the invitation.']]);
        }

        $project = $invitation->project;
        if ($response === 'accepted') {
            $this->sessionService->assertCanAcceptProjectInvitation($user, $project);
        }

        return DB::transaction(function () use ($user, $invitation, $response, $comments) {
            $project = $invitation->project;

            if ($response === 'accepted') {
                $invitation->update([
                    'status' => 'accepted',
                    'responded_at' => now(),
                    'response_comments' => $comments,
                ]);

                ProjectMember::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'role' => 'member',
                ]);

                $user->update(['is_proposal_enrolled' => true]);

                $this->log($project, 'group_confirmed', "{$user->name} accepted the group invitation", $user, 'student', $comments);

                NotificationService::send(
                    $project->student_id,
                    'Invitation Accepted',
                    $comments
                        ? "{$user->name} accepted your group invitation. Comment: {$comments}"
                        : "{$user->name} accepted your group invitation.",
                    'success',
                    ['project_id' => $project->id]
                );

                $this->checkGroupComplete($project);
            } else {
                $invitation->update([
                    'status' => 'rejected',
                    'responded_at' => now(),
                    'response_comments' => $comments,
                ]);

                $this->log($project, 'group_confirmed', "{$user->name} rejected the group invitation", $user, 'student', $comments);

                NotificationService::send(
                    $project->student_id,
                    'Invitation Rejected',
                    "{$user->name} rejected your group invitation. Comment: {$comments}",
                    'warning',
                    ['project_id' => $project->id]
                );

                $project->update(['workflow_stage' => 'invitations_pending']);
            }

            return $this->loadProject($project->fresh());
        });
    }

    public function acceptInvitationOnBehalf(
        User $actor,
        Project $project,
        ProjectInvitation $invitation,
        ?string $comments = null,
    ): Project {
        if ($invitation->project_id !== $project->id) {
            throw ValidationException::withMessages(['invitation' => ['Invitation not found for this project.']]);
        }

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages(['invitation' => ['Only pending invitations can be accepted.']]);
        }

        if (! FypProposal::canManageProjectTeam($actor)) {
            throw ValidationException::withMessages(['invitation' => ['Unauthorized to accept invitations on behalf of students.']]);
        }

        if (($project->workflow_stage ?? '') !== 'invitations_pending') {
            throw ValidationException::withMessages(['invitation' => ['Invitations can only be accepted during team formation.']]);
        }

        $invitee = $invitation->invitee;
        if (! $invitee) {
            throw ValidationException::withMessages(['invitation' => ['Invited student not found.']]);
        }

        $this->sessionService->assertCanAcceptProjectInvitation($invitee, $project);

        return DB::transaction(function () use ($actor, $project, $invitation, $invitee, $comments) {
            $note = $comments ?: 'Accepted by FYP office on behalf of the student.';
            $actorRole = $actor->roles->first()?->name ?? 'admin';

            $invitation->update([
                'status' => 'accepted',
                'responded_at' => now(),
                'response_comments' => $note,
            ]);

            ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $invitee->id,
                'role' => 'member',
            ]);

            $invitee->update(['is_proposal_enrolled' => true]);

            $this->log(
                $project,
                'group_confirmed',
                "{$invitee->name}'s invitation was accepted by FYP office",
                $actor,
                $actorRole,
                $comments
            );

            NotificationService::send(
                $invitee->id,
                'Added to FYP Group',
                "You were added to the proposal \"{$project->title}\" by the FYP office. {$note}",
                'info',
                ['project_id' => $project->id]
            );

            NotificationService::send(
                $project->student_id,
                'Invitation Accepted',
                $comments
                    ? "{$invitee->name}'s invitation was accepted by the FYP office. Comment: {$comments}"
                    : "{$invitee->name}'s invitation was accepted by the FYP office.",
                'success',
                ['project_id' => $project->id]
            );

            $this->checkGroupComplete($project->fresh());

            return $this->loadProject($project->fresh());
        });
    }

    public function checkGroupComplete(Project $project): void
    {
        $pending = $project->invitations()->where('status', 'pending')->count();
        $memberCount = $project->members()->where('status', 'active')->count();

        if ($pending > 0) {
            return;
        }

        if ($memberCount < FypProposal::minMembers()) {
            $project->update(['workflow_stage' => 'invitations_pending']);

            return;
        }

        $project->update(['workflow_stage' => 'supervisor_pending']);

        $this->log($project, 'group_confirmed', 'All group members confirmed', null, 'system');

        if ($project->supervisor_id) {
            NotificationService::send(
                $project->supervisor_id,
                'Supervision Request',
                "Group proposal \"{$project->title}\" is awaiting your supervision decision.",
                'info',
                ['project_id' => $project->id, 'type' => 'supervisor_request']
            );
        }
    }

    public function returnToTeamFormation(User $actor, Project $project, ?string $comments = null): Project
    {
        if (! FypProposal::canReturnProjectToTeamFormation($actor, $project->workflow_stage ?? 'draft')) {
            throw ValidationException::withMessages([
                'project' => ['You cannot return this proposal to team formation at the current stage.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $project, $comments) {
            EvaluatorReview::where('project_id', $project->id)->delete();
            $project->evaluators()->delete();

            $project->update([
                'workflow_stage' => 'invitations_pending',
                'supervisor_status' => 'pending',
                'supervisor_rejection_feedback' => null,
                'evaluator_resubmit_mode' => null,
            ]);

            $this->log(
                $project,
                'group_confirmed',
                'Proposal returned to team formation stage',
                $actor,
                $actor->roles->first()?->name ?? 'admin',
                $comments
            );

            $leaderMessage = $comments
                ? "Your proposal was returned to the team formation stage. You can invite more members. Reason: {$comments}"
                : 'Your proposal was returned to the team formation stage. You can invite more members now.';

            NotificationService::send(
                $project->student_id,
                'Return to Team Formation',
                $leaderMessage,
                'info',
                ['project_id' => $project->id]
            );

            $this->notifyGroup(
                $project,
                'Team Formation Reopened',
                'The FYP office returned your group to the team formation stage. The group leader can invite more members.',
                'info'
            );

            ActivityLogService::log(
                'update',
                'projects',
                "Returned proposal {$project->title} to team formation",
                $actor->id,
                $project->id
            );

            return $this->loadProject($project->fresh());
        });
    }

    public function supervisorRespond(User $actor, Project $project, bool $accept, ?string $feedback = null): Project
    {
        $isAssignedSupervisor = (int) $project->supervisor_id === (int) $actor->id;
        $isOfficeActor = ! $isAssignedSupervisor && FypProposal::canManageSupervisorInvitations($actor);

        if (! $isAssignedSupervisor && ! $isOfficeActor) {
            throw ValidationException::withMessages(['supervisor' => ['Unauthorized to respond to this supervision request.']]);
        }

        if ($project->workflow_stage !== 'supervisor_pending') {
            throw ValidationException::withMessages(['project' => ['Supervisor review is not available at this stage.']]);
        }

        if (! $project->supervisor_id) {
            throw ValidationException::withMessages(['project' => ['No supervisor is assigned to this proposal.']]);
        }

        $supervisor = $project->supervisor;
        $actorRole = $isAssignedSupervisor ? 'supervisor' : ($actor->roles->first()?->name ?? 'admin');

        return DB::transaction(function () use ($actor, $supervisor, $project, $accept, $feedback, $isOfficeActor, $actorRole) {
            if ($accept) {
                $project->update([
                    'supervisor_status' => 'accepted',
                    'supervisor_rejection_feedback' => null,
                    'workflow_stage' => 'committee_review',
                ]);

                if ($isOfficeActor) {
                    $this->log(
                        $project,
                        'supervisor_review',
                        "Supervision accepted by FYP office for {$supervisor->name}",
                        $actor,
                        $actorRole,
                        $feedback
                    );

                    $this->notifyGroup(
                        $project,
                        'Supervisor Accepted',
                        "Supervision for your proposal was accepted by the FYP office. {$supervisor->name} will supervise your group.",
                        'success'
                    );

                    NotificationService::send(
                        $supervisor->id,
                        'Supervision Accepted',
                        "The FYP office accepted the supervision request for \"{$project->title}\" on your behalf.",
                        'info',
                        ['project_id' => $project->id]
                    );
                } else {
                    $this->log($project, 'supervisor_review', 'Supervisor accepted supervision', $actor, 'supervisor', $feedback);

                    $this->notifyGroup(
                        $project,
                        'Supervisor Accepted',
                        "{$supervisor->name} accepted to supervise your proposal.",
                        'success'
                    );
                }

                $this->notifyCommittee($project);
            } else {
                $project->update([
                    'supervisor_status' => 'rejected',
                    'supervisor_rejection_feedback' => $feedback,
                    'workflow_stage' => 'supervisor_rejected',
                ]);

                if ($isOfficeActor) {
                    $this->log(
                        $project,
                        'supervisor_review',
                        "Supervision declined by FYP office for {$supervisor->name}",
                        $actor,
                        $actorRole,
                        $feedback
                    );

                    $this->notifyGroup(
                        $project,
                        'Supervisor Declined',
                        $feedback
                            ? "The FYP office declined supervision by {$supervisor->name}. Comment: {$feedback} Please select a new supervisor."
                            : "The FYP office declined supervision by {$supervisor->name}. Please select a new supervisor.",
                        'warning'
                    );

                    NotificationService::send(
                        $supervisor->id,
                        'Supervision Declined',
                        $feedback
                            ? "The FYP office declined the supervision request for \"{$project->title}\" on your behalf. Comment: {$feedback}"
                            : "The FYP office declined the supervision request for \"{$project->title}\" on your behalf.",
                        'warning',
                        ['project_id' => $project->id]
                    );
                } else {
                    $this->log($project, 'supervisor_review', 'Supervisor rejected supervision', $actor, 'supervisor', $feedback);

                    $this->notifyGroup(
                        $project,
                        'Supervisor Declined',
                        "{$supervisor->name} declined supervision. Please select a new supervisor.",
                        'warning'
                    );
                }
            }

            return $this->loadProject($project->fresh());
        });
    }

    /**
     * Lets the supervisor (or the FYP office on their behalf) send the initial
     * proposal submission back to students for changes without declining
     * supervision outright — unlike supervisorRespond()'s reject branch, which
     * means "I won't supervise this, pick someone else." The supervisor stays
     * assigned and accepted; the proposal re-enters the same revision_required
     * -> resubmit -> supervisor_revision_pending loop used for later rounds.
     */
    public function supervisorRequestRevision(User $actor, Project $project, ?string $feedback = null): Project
    {
        $isAssignedSupervisor = (int) $project->supervisor_id === (int) $actor->id;
        $isOfficeActor = ! $isAssignedSupervisor && FypProposal::canManageSupervisorInvitations($actor);

        if (! $isAssignedSupervisor && ! $isOfficeActor) {
            throw ValidationException::withMessages(['supervisor' => ['Unauthorized to review this proposal.']]);
        }

        if ($project->workflow_stage !== 'supervisor_pending') {
            throw ValidationException::withMessages(['project' => ['Supervisor review is not available at this stage.']]);
        }

        if (! $project->supervisor_id) {
            throw ValidationException::withMessages(['project' => ['No supervisor is assigned to this proposal.']]);
        }

        if (blank($feedback)) {
            throw ValidationException::withMessages([
                'feedback' => ['Please provide comments when returning the proposal for revision.'],
            ]);
        }

        $supervisor = $project->supervisor;
        $actorRole = $isAssignedSupervisor ? 'supervisor' : ($actor->roles->first()?->name ?? 'admin');

        return DB::transaction(function () use ($actor, $supervisor, $project, $feedback, $isOfficeActor, $actorRole) {
            $project->update([
                'supervisor_status' => 'accepted',
                'supervisor_rejection_feedback' => null,
                'workflow_stage' => 'revision_required',
                'supervisor_revision_feedback' => $feedback,
            ]);

            if ($isOfficeActor) {
                $this->log(
                    $project,
                    'supervisor_review',
                    "Revision requested by FYP office on behalf of {$supervisor->name}",
                    $actor,
                    $actorRole,
                    $feedback
                );

                $this->notifyGroup(
                    $project,
                    'Revision Required',
                    "The FYP office requested changes to your proposal on {$supervisor->name}'s behalf. Comment: {$feedback}",
                    'warning'
                );

                NotificationService::send(
                    $supervisor->id,
                    'Revision Requested on Your Behalf',
                    "The FYP office requested a revision for \"{$project->title}\" on your behalf. Comment: {$feedback}",
                    'info',
                    ['project_id' => $project->id]
                );
            } else {
                $this->log(
                    $project,
                    'supervisor_review',
                    'Supervisor requested changes before accepting the proposal',
                    $actor,
                    'supervisor',
                    $feedback
                );

                $this->notifyGroup(
                    $project,
                    'Revision Required',
                    "Your supervisor requested changes before proceeding: {$feedback}",
                    'warning'
                );
            }

            return $this->loadProject($project->fresh());
        });
    }

    public function changeSupervisor(User $leader, Project $project, int $supervisorId): Project
    {
        if ($project->student_id !== $leader->id) {
            throw ValidationException::withMessages(['project' => ['Only the group leader can change supervisor.']]);
        }

        if (! in_array($project->workflow_stage, ['supervisor_rejected', 'supervisor_pending'], true)) {
            throw ValidationException::withMessages(['project' => ['Supervisor can only be changed after a rejection or while pending.']]);
        }

        $this->sessionService->assertCanEditProject($leader, $project);

        $supervisor = User::role('supervisor')->find($supervisorId);
        if (! $supervisor) {
            throw ValidationException::withMessages(['supervisor_id' => ['Invalid supervisor selected.']]);
        }

        if (! $this->supervisorCapacity->hasCapacity($supervisor, 'proposal', $project->id)) {
            throw ValidationException::withMessages([
                'supervisor_id' => ['This supervisor has reached the maximum number of proposal groups they can supervise.'],
            ]);
        }

        $this->assertFacultyNotEvaluatorOnProject($project, (int) $supervisor->id);

        $project->update([
            'supervisor_id' => $supervisor->id,
            'supervisor_status' => 'pending',
            'supervisor_rejection_feedback' => null,
            'workflow_stage' => 'supervisor_pending',
        ]);

        $this->log($project, 'supervisor_review', 'New supervisor selected by group', $leader, 'student');

        NotificationService::send(
            $supervisor->id,
            'Supervision Request',
            "Group proposal \"{$project->title}\" is awaiting your supervision decision.",
            'info',
            ['project_id' => $project->id, 'type' => 'supervisor_request']
        );

        return $this->loadProject($project->fresh());
    }

    public function assignEvaluators(User $assigner, Project $project, array $evaluatorIds, ?string $resubmitMode = null): Project
    {
        if (! $this->canAssignEvaluators($assigner)) {
            throw ValidationException::withMessages(['evaluator' => ['Unauthorized to assign evaluators.']]);
        }

        if (! in_array($project->workflow_stage, FypProposal::evaluatorAssignmentStages(), true)) {
            throw ValidationException::withMessages([
                'project' => ['Evaluators cannot be changed at the current workflow stage.'],
            ]);
        }

        if ($resubmitMode && ! FypProposal::isValidEvaluatorResubmitMode($resubmitMode)) {
            throw ValidationException::withMessages([
                'evaluator_resubmit_mode' => ['Invalid evaluator resubmit mode selected.'],
            ]);
        }

        $evaluatorIds = collect($evaluatorIds)->unique()->values();

        if ($evaluatorIds->count() < FypProposal::minEvaluators() || $evaluatorIds->count() > FypProposal::maxEvaluators()) {
            throw ValidationException::withMessages([
                'evaluator_ids' => ['Assign between '.FypProposal::minEvaluators().' and '.FypProposal::maxEvaluators().' evaluators.'],
            ]);
        }

        foreach ($evaluatorIds as $evaluatorId) {
            $this->assertFacultyNotSupervisorOnProject($project, (int) $evaluatorId);
        }

        foreach ($evaluatorIds as $evaluatorId) {
            $evaluator = User::find($evaluatorId);
            if ($evaluator && ! $this->evaluatorCapacity->hasCapacity($evaluator, 'proposal', $project->id)) {
                throw ValidationException::withMessages([
                    'evaluator_ids' => ["{$evaluator->name} has reached their evaluation limit for this phase."],
                ]);
            }
        }

        $isUpdate = $project->evaluators()->exists();
        $preserveReviews = $project->workflow_stage === 'revision_required';

        return DB::transaction(function () use ($assigner, $project, $evaluatorIds, $isUpdate, $preserveReviews, $resubmitMode) {
            if ($preserveReviews) {
                EvaluatorReview::where('project_id', $project->id)
                    ->where('fyp_phase', 'proposal')
                    ->whereNotIn('evaluator_id', $evaluatorIds->all())
                    ->delete();
            } else {
                EvaluatorReview::where('project_id', $project->id)
                    ->where('fyp_phase', 'proposal')
                    ->delete();
            }

            $project->evaluators()->delete();

            foreach ($evaluatorIds as $evaluatorId) {
                $evaluator = User::role('evaluator')->find($evaluatorId);
                if (! $evaluator) {
                    throw ValidationException::withMessages(['evaluator_ids' => ['Invalid evaluator selected.']]);
                }

                ProjectEvaluator::create([
                    'project_id' => $project->id,
                    'evaluator_id' => $evaluatorId,
                    'assigned_by' => $assigner->id,
                ]);

                if (! $preserveReviews) {
                    NotificationService::send(
                        $evaluatorId,
                        $isUpdate ? 'Evaluation Reassigned' : 'Evaluation Assigned',
                        ($isUpdate ? 'You have been reassigned to evaluate proposal: ' : 'You have been assigned to evaluate proposal: ')."{$project->title}",
                        'info',
                        ['project_id' => $project->id, 'type' => 'evaluator_assignment']
                    );
                }
            }

            $nextStage = match ($project->workflow_stage) {
                'committee_review' => 'evaluator_review',
                default => $project->workflow_stage,
            };

            $updates = ['workflow_stage' => $nextStage];
            if ($resubmitMode) {
                $updates['evaluator_resubmit_mode'] = $resubmitMode;
            }

            $project->update($updates);

            $this->log(
                $project,
                'evaluators_assigned',
                $isUpdate ? 'Evaluators updated by authorized user' : 'Evaluators assigned by committee',
                $assigner,
                $assigner->roles->first()?->name ?? 'admin',
                $resubmitMode ? 'Resubmit review mode: '.FypProposal::evaluatorResubmitModeLabel($resubmitMode) : null
            );

            return $this->loadProject($project->fresh());
        });
    }

    public function canAssignEvaluators(User $user): bool
    {
        return FypProposal::canAssignEvaluators($user);
    }

    public function submitEvaluatorReview(
        User $actor,
        Project $project,
        string $decision,
        ?string $comments = null,
        ?int $evaluatorId = null,
        array $answers = []
    ): Project {
        if ($project->workflow_stage !== 'evaluator_review') {
            throw ValidationException::withMessages(['project' => ['Evaluator review is not open.']]);
        }

        $scoredAnswers = $this->validateEvaluatorReviewAnswers('proposal', $answers);

        if (blank($comments)) {
            throw ValidationException::withMessages([
                'comments' => ['Please provide a final comment for this evaluation.'],
            ]);
        }

        $isAssignedEvaluator = $project->evaluators()->where('evaluator_id', $actor->id)->exists();
        $isOfficeActor = FypProposal::canManageEvaluatorReviews($actor);

        if ($evaluatorId === null && $isAssignedEvaluator) {
            $evaluatorId = $actor->id;
        }

        if ($evaluatorId === null) {
            throw ValidationException::withMessages([
                'evaluator_id' => ['Select an evaluator to submit this review on behalf of.'],
            ]);
        }

        if ((int) $evaluatorId === (int) $actor->id) {
            if (! $isAssignedEvaluator) {
                throw ValidationException::withMessages(['evaluator' => ['You are not assigned to this project.']]);
            }
        } elseif (! $isOfficeActor) {
            throw ValidationException::withMessages(['evaluator' => ['Unauthorized to submit this evaluation.']]);
        }

        if (! $project->evaluators()->where('evaluator_id', $evaluatorId)->exists()) {
            throw ValidationException::withMessages(['evaluator_id' => ['Selected evaluator is not assigned to this project.']]);
        }

        $this->assertFacultyNotSupervisorOnProject($project, (int) $evaluatorId, 'evaluator_id');

        $evaluator = User::find($evaluatorId);
        if (! $evaluator) {
            throw ValidationException::withMessages(['evaluator_id' => ['Invalid evaluator selected.']]);
        }

        $isOfficeSubmission = $isOfficeActor && (int) $evaluatorId !== (int) $actor->id;
        $actorRole = $isOfficeSubmission ? ($actor->roles->first()?->name ?? 'admin') : 'evaluator';

        $this->persistEvaluatorReview(
            $project,
            'proposal',
            (int) $evaluatorId,
            $decision,
            $comments,
            $scoredAnswers
        );

        if ($isOfficeSubmission) {
            $this->log(
                $project,
                'evaluator_review',
                "Evaluation submitted by FYP office on behalf of {$evaluator->name}: {$decision}",
                $actor,
                $actorRole,
                $comments
            );

            NotificationService::send(
                $evaluatorId,
                'Evaluation Submitted',
                "The FYP office submitted your {$decision} review for proposal \"{$project->title}\" on your behalf.",
                'info',
                ['project_id' => $project->id]
            );
        } else {
            $this->log(
                $project,
                'evaluator_review',
                'Evaluator submitted review: '.$decision,
                $actor,
                'evaluator',
                $comments
            );
        }

        return $this->evaluateReviewerOutcomes($project->fresh());
    }

    protected function evaluateReviewerOutcomes(Project $project): Project
    {
        if ($this->pendingEvaluatorReviewCount($project) > 0) {
            return $this->loadProject($project);
        }

        $reviews = $project->evaluatorReviews()->where('fyp_phase', 'proposal')->with('evaluator')->get();
        $summary = $this->summarizeEvaluatorReviews($reviews);

        $project->update(['workflow_stage' => 'committee_final']);

        $this->log(
            $project,
            'committee_final',
            'All assigned evaluators submitted reviews. Awaiting committee decision. '.$summary,
            null,
            'system'
        );

        $this->notifyCommittee(
            $project,
            'Committee Final Review',
            'All evaluator reviews are complete. Please forward to Committee Head or return the proposal for student revision.'
        );

        return $this->loadProject($project->fresh());
    }

    protected function pendingEvaluatorReviewCount(Project $project): int
    {
        $assignedIds = $project->evaluators()->pluck('evaluator_id');
        $reviewedIds = $project->evaluatorReviews()->where('fyp_phase', 'proposal')->pluck('evaluator_id');

        return $assignedIds->diff($reviewedIds)->count();
    }

    public function summarizeEvaluatorReviews($reviews): string
    {
        $accepted = $reviews->where('decision', 'accepted')->count();
        $revision = $reviews->where('decision', 'revision_required')->count();
        $rejected = $reviews->where('decision', 'rejected')->count();
        $markSummary = $reviews
            ->filter(fn ($review) => $review->marks !== null)
            ->map(fn ($review) => ($review->evaluator?->name ?? 'Evaluator').': '.$review->marks.'/'.($review->max_marks ?? '?'))
            ->implode(', ');

        $summary = sprintf(
            'Summary: %d accepted, %d revision requested, %d rejected.',
            $accepted,
            $revision,
            $rejected
        );

        if ($markSummary !== '') {
            $summary .= ' Marks: '.$markSummary.'.';
        }

        return $summary;
    }

    /**
     * Every active question in the phase's question bank must be answered, each within its
     * own max marks. Returns the total, the bank's total possible marks (snapshotted for
     * this submission), and the per-question rows ready to persist.
     */
    public function validateEvaluatorReviewAnswers(string $phase, array $answers): array
    {
        $questions = $this->questionBank->activeQuestionsForPhase($phase);

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'answers' => ['No evaluation questions have been configured for this phase yet. Ask the FYP office to set up the question bank first.'],
            ]);
        }

        $answersByQuestion = collect($answers)->keyBy(fn ($answer) => (int) ($answer['question_id'] ?? 0));
        $rows = [];
        $total = 0;
        $maxTotal = 0;

        foreach ($questions as $question) {
            $answer = $answersByQuestion->get($question->id);
            $maxTotal += $question->max_marks;

            if ($answer === null || $answer['marks'] === null || $answer['marks'] === '') {
                throw ValidationException::withMessages([
                    'answers' => ["Please provide marks for every question (missing: \"{$question->text}\")."],
                ]);
            }

            $marks = $answer['marks'];

            if (! is_numeric($marks) || (int) $marks != $marks) {
                throw ValidationException::withMessages([
                    'answers' => ["Marks for \"{$question->text}\" must be a whole number."],
                ]);
            }

            $marks = (int) $marks;

            if ($marks < 0 || $marks > $question->max_marks) {
                throw ValidationException::withMessages([
                    'answers' => ["Marks for \"{$question->text}\" must be between 0 and {$question->max_marks}."],
                ]);
            }

            $rows[] = [
                'question_id' => $question->id,
                'marks_awarded' => $marks,
                'comment' => $answer['comment'] ?? null,
            ];
            $total += $marks;
        }

        return ['total' => $total, 'max_total' => $maxTotal, 'rows' => $rows];
    }

    public function persistEvaluatorReview(
        Project $project,
        string $fypPhase,
        int $evaluatorId,
        string $decision,
        ?string $comments,
        array $scoredAnswers,
    ): EvaluatorReview {
        return DB::transaction(function () use ($project, $fypPhase, $evaluatorId, $decision, $comments, $scoredAnswers) {
            EvaluatorReview::query()
                ->where('project_id', $project->id)
                ->where('fyp_phase', $fypPhase)
                ->where('evaluator_id', $evaluatorId)
                ->delete();

            $review = EvaluatorReview::create([
                'project_id' => $project->id,
                'evaluator_id' => $evaluatorId,
                'fyp_phase' => $fypPhase,
                'decision' => $decision,
                'marks' => $scoredAnswers['total'],
                'max_marks' => $scoredAnswers['max_total'],
                'comments' => $comments,
                'reviewed_at' => now(),
            ]);

            foreach ($scoredAnswers['rows'] as $row) {
                $review->answers()->create($row);
            }

            return $review;
        });
    }

    public function resubmitProposal(User $leader, Project $project, array $data): Project
    {
        if ($project->student_id !== $leader->id) {
            throw ValidationException::withMessages(['project' => ['Only the group leader can resubmit.']]);
        }

        if ($project->workflow_stage !== 'revision_required') {
            throw ValidationException::withMessages(['project' => ['Proposal is not in revision state.']]);
        }

        if (! $project->supervisor_id) {
            throw ValidationException::withMessages(['project' => ['A supervisor must be assigned before resubmitting a revised proposal.']]);
        }

        $this->sessionService->assertCanEditProject($leader, $project);

        $proposalPhase = $project->phase('proposal');
        $document = $this->documents->replace(
            $data['proposal_file'],
            $project->id,
            $proposalPhase?->attachment
        );

        return DB::transaction(function () use ($leader, $project, $proposalPhase, $document) {
            $proposalPhase?->update([
                'attachment' => $document['path'],
                'content' => $document['original_name'],
                'status' => 'draft',
            ]);

            $project->update([
                'workflow_stage' => 'supervisor_revision_pending',
                'supervisor_revision_feedback' => null,
            ]);

            $this->log(
                $project,
                'supervisor_review',
                'Revised proposal submitted by students',
                $leader,
                'student',
                $document['original_name'] ?? null
            );

            NotificationService::send(
                $project->supervisor_id,
                'Revised Proposal for Review',
                "Group proposal \"{$project->title}\" has been revised and requires your review before it proceeds.",
                'info',
                ['project_id' => $project->id, 'type' => 'supervisor_revision_review']
            );

            $this->notifyGroup(
                $project,
                'Revision Submitted',
                'Your revised proposal has been sent to your supervisor for review.',
                'info'
            );

            return $this->loadProject($project->fresh());
        });
    }

    public function supervisorRevisionReview(User $actor, Project $project, bool $proceed, ?string $feedback = null): Project
    {
        $isAssignedSupervisor = (int) $project->supervisor_id === (int) $actor->id;
        $isOfficeActor = ! $isAssignedSupervisor && FypProposal::canManageSupervisorInvitations($actor);

        if (! $isAssignedSupervisor && ! $isOfficeActor) {
            throw ValidationException::withMessages(['supervisor' => ['Unauthorized.']]);
        }

        if ($project->workflow_stage !== 'supervisor_revision_pending') {
            throw ValidationException::withMessages(['project' => ['Supervisor revision review is not available at this stage.']]);
        }

        if (! $proceed && blank($feedback)) {
            throw ValidationException::withMessages([
                'feedback' => ['Please provide comments when returning the proposal to students.'],
            ]);
        }

        $supervisor = $project->supervisor;
        $actorRole = $isAssignedSupervisor ? 'supervisor' : ($actor->roles->first()?->name ?? 'admin');

        return DB::transaction(function () use ($actor, $supervisor, $project, $proceed, $feedback, $isOfficeActor, $actorRole) {
            if ($proceed) {
                $this->advanceRevisedProposalAfterSupervisorApproval($project, $actor, $feedback, $actorRole);

                if ($isOfficeActor) {
                    $this->log(
                        $project,
                        'supervisor_review',
                        "Revised proposal approved by FYP office on behalf of {$supervisor->name}",
                        $actor,
                        $actorRole,
                        $feedback
                    );

                    $this->notifyGroup(
                        $project,
                        'Revision Approved by Supervisor',
                        "The FYP office approved your revised proposal on {$supervisor->name}'s behalf. It will move to the next review stage.",
                        'success'
                    );

                    NotificationService::send(
                        $supervisor->id,
                        'Revision Approved on Your Behalf',
                        "The FYP office approved the revised proposal for \"{$project->title}\" on your behalf.",
                        'info',
                        ['project_id' => $project->id]
                    );
                } else {
                    $this->log(
                        $project,
                        'supervisor_review',
                        'Supervisor approved revised proposal to proceed',
                        $actor,
                        'supervisor',
                        $feedback
                    );

                    $this->notifyGroup(
                        $project,
                        'Revision Approved by Supervisor',
                        'Your supervisor approved your revised proposal. It will move to the next review stage.',
                        'success'
                    );
                }
            } else {
                $project->update([
                    'workflow_stage' => 'revision_required',
                    'supervisor_revision_feedback' => $feedback,
                ]);

                if ($isOfficeActor) {
                    $this->log(
                        $project,
                        'supervisor_review',
                        "Revised proposal returned by FYP office on behalf of {$supervisor->name}",
                        $actor,
                        $actorRole,
                        $feedback
                    );

                    $this->notifyGroup(
                        $project,
                        'Further Revision Required',
                        "The FYP office returned your revised proposal on {$supervisor->name}'s behalf with comments: {$feedback}",
                        'warning'
                    );

                    NotificationService::send(
                        $supervisor->id,
                        'Revision Returned on Your Behalf',
                        "The FYP office returned the revised proposal for \"{$project->title}\" on your behalf. Comment: {$feedback}",
                        'warning',
                        ['project_id' => $project->id]
                    );
                } else {
                    $this->log(
                        $project,
                        'supervisor_review',
                        'Supervisor returned revised proposal for further changes',
                        $actor,
                        'supervisor',
                        $feedback
                    );

                    $this->notifyGroup(
                        $project,
                        'Further Revision Required',
                        "Your supervisor returned the revised proposal with comments: {$feedback}",
                        'warning'
                    );
                }
            }

            return $this->loadProject($project->fresh());
        });
    }

    protected function advanceRevisedProposalAfterSupervisorApproval(Project $project, User $actor, ?string $comments = null, string $actorRole = 'supervisor'): void
    {
        $mode = $project->evaluator_resubmit_mode ?? 'all';
        $assignedIds = $project->evaluators()->pluck('evaluator_id');

        if ($mode === 'all') {
            EvaluatorReview::where('project_id', $project->id)->where('fyp_phase', 'proposal')->delete();
            $evaluatorsToNotify = $assignedIds;
        } else {
            $negativeEvaluatorIds = $project->evaluatorReviews()
                ->where('fyp_phase', 'proposal')
                ->whereIn('decision', ['rejected', 'revision_required'])
                ->pluck('evaluator_id');

            EvaluatorReview::where('project_id', $project->id)
                ->where('fyp_phase', 'proposal')
                ->whereIn('evaluator_id', $negativeEvaluatorIds)
                ->delete();

            $reviewedIds = $project->evaluatorReviews()->where('fyp_phase', 'proposal')->pluck('evaluator_id');
            $evaluatorsToNotify = $negativeEvaluatorIds
                ->merge($assignedIds->diff($reviewedIds))
                ->unique()
                ->values();
        }

        $project = $project->fresh();
        $pendingReviews = $this->pendingEvaluatorReviewCount($project);

        if ($pendingReviews === 0) {
            $project->update(['workflow_stage' => 'committee_final']);

            $this->log(
                $project,
                'committee_final',
                'Revised proposal approved by supervisor. No further evaluator review required. Awaiting committee decision.',
                $actor,
                $actorRole,
                $comments
            );

            $this->notifyCommittee(
                $project,
                'Committee Final Review',
                "Revised proposal \"{$project->title}\" is ready for committee review."
            );
        } else {
            $project->update(['workflow_stage' => 'evaluator_review']);

            $this->log(
                $project,
                'evaluator_review',
                'Revised proposal approved by supervisor and sent for evaluator review',
                $actor,
                $actorRole,
                $comments
            );

            foreach ($evaluatorsToNotify as $evaluatorId) {
                NotificationService::send(
                    $evaluatorId,
                    'Proposal Resubmitted',
                    "Proposal \"{$project->title}\" has been revised and requires your review again.",
                    'info',
                    ['project_id' => $project->id]
                );
            }
        }
    }

    public function committeeFinalReview(User $reviewer, Project $project, bool $approve, ?string $comments = null, ?string $resubmitMode = null): Project
    {
        if (! $reviewer->hasAnyRole(FypProposal::settings()['committee_review_roles'] ?? [])) {
            throw ValidationException::withMessages(['reviewer' => ['Unauthorized.']]);
        }

        if ($project->workflow_stage !== 'committee_final') {
            throw ValidationException::withMessages(['project' => ['Committee final review is not available.']]);
        }

        if ($approve) {
            $project->update(['workflow_stage' => 'committee_head_approval']);

            $this->log($project, 'committee_final', 'Committee forwarded to Committee Head', $reviewer, 'fyp-committee-member', $comments);

            User::query()
                ->whereIn('id', $this->programScope->programHeadIds((int) $project->program_id))
                ->each(function ($head) use ($project) {
                    NotificationService::send(
                        $head->id,
                        'Final Approval Required',
                        "Proposal \"{$project->title}\" requires Committee Head approval.",
                        'info',
                        ['project_id' => $project->id]
                    );
                });
        } else {
            if (! FypProposal::isValidEvaluatorResubmitMode($resubmitMode)) {
                throw ValidationException::withMessages([
                    'evaluator_resubmit_mode' => ['Please select who must review the proposal after resubmission.'],
                ]);
            }

            $project->update([
                'workflow_stage' => 'revision_required',
                'evaluator_resubmit_mode' => $resubmitMode,
            ]);

            $this->log(
                $project,
                'committee_final',
                'Committee returned proposal for revision',
                $reviewer,
                'fyp-committee-member',
                $comments
            );

            $this->notifyGroup($project, 'Revision Required', 'Committee requested revision on your proposal.', 'warning');
        }

        return $this->loadProject($project->fresh());
    }

    public function committeeHeadApprove(User $reviewer, Project $project, bool $approve, ?string $comments = null, ?string $resubmitMode = null): Project
    {
        if (! $reviewer->hasRole('fyp-committee-head')) {
            throw ValidationException::withMessages(['reviewer' => ['Only Committee Head can perform final approval.']]);
        }

        if ($project->workflow_stage !== 'committee_head_approval') {
            throw ValidationException::withMessages(['project' => ['Final approval is not available at this stage.']]);
        }

        if ($approve) {
            $project->update(['workflow_stage' => 'approved']);
            $project->phase('proposal')?->update(['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $reviewer->id]);

            $this->log($project, 'approved', 'Proposal approved by Committee Head', $reviewer, 'fyp-committee-head', $comments);

            $this->notifyGroup($project, 'Proposal Approved', 'Congratulations! Your proposal has been approved.', 'success');
        } else {
            if (! FypProposal::isValidEvaluatorResubmitMode($resubmitMode)) {
                throw ValidationException::withMessages([
                    'evaluator_resubmit_mode' => ['Please select who must review the proposal after resubmission.'],
                ]);
            }

            $project->update([
                'workflow_stage' => 'revision_required',
                'evaluator_resubmit_mode' => $resubmitMode,
            ]);

            $this->log($project, 'committee_head_approval', 'Committee Head returned proposal for revision', $reviewer, 'fyp-committee-head', $comments);

            $this->notifyGroup($project, 'Revision Required', 'Committee Head requested revision on your proposal.', 'warning');
        }

        return $this->loadProject($project->fresh());
    }

    public function getPendingInvitations(User $user)
    {
        return ProjectInvitation::with(['project.student', 'inviter'])
            ->where('invitee_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->get();
    }

    public function getEligibleMembers(?Project $project = null, ?string $search = null, ?User $excludeUser = null)
    {
        $blockedInviteeIds = $project
            ? $project->invitations()
                ->whereIn('status', ['pending', 'accepted'])
                ->pluck('invitee_id')
            : collect();

        $scopeDepartmentId = $project
            ? $project->student?->department_id
            : $excludeUser?->department_id;

        $proposalSession = null;
        if ($project?->proposal_session_id) {
            $proposalSession = ProposalSession::find($project->proposal_session_id);
        } elseif ($excludeUser) {
            $proposalSession = $this->sessionService->currentProposalSessionForUser($excludeUser);
        }

        return User::role('student')
            ->where('status', 'active')
            ->when($scopeDepartmentId, fn (Builder $q) => $q->where('department_id', $scopeDepartmentId))
            ->when(
                $proposalSession,
                fn (Builder $q) => $this->sessionService->applyStudentSessionScope($q, $proposalSession)
            )
            ->whereDoesntHave('projectMemberships', fn ($q) => $q->activeMembership())
            ->whereDoesntHave('ledProject')
            ->whereDoesntHave('pendingInvitations')
            ->when($excludeUser, fn ($q) => $q->where('id', '!=', $excludeUser->id))
            ->when($project, fn ($q) => $q->whereNotIn('id', $project->members()->pluck('user_id')))
            ->when($blockedInviteeIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $blockedInviteeIds))
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('registration_no', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'registration_no', 'program', 'department']);
    }

    /**
     * Students an admin/supervisor can add directly to this project right now:
     * unassigned students (free to join any phase) plus students who are active
     * members (not leaders) of a DIFFERENT project in the exact same phase, who
     * can be moved over as a same-phase transfer.
     */
    public function getTransferableMembers(Project $project, ?string $search = null)
    {
        return User::role('student')
            ->where('status', 'active')
            ->whereDoesntHave('ledProject')
            ->whereNotIn('id', $project->members()->pluck('user_id'))
            ->where(function (Builder $q) use ($project) {
                $q->whereDoesntHave('projectMemberships', fn ($m) => $m->activeMembership())
                    ->orWhereHas('projectMemberships', function ($m) use ($project) {
                        $m->activeMembership()->whereHas('project', fn (Builder $p) => $p
                            ->where('id', '!=', $project->id)
                            ->where('current_phase', $project->current_phase));
                    });
            })
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('registration_no', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'registration_no', 'program', 'department']);
    }

    public function teamInviteMeta(Project $project): array
    {
        $memberCount = $project->members()->where('status', 'active')->count();
        $pendingCount = $project->invitations()->where('status', 'pending')->count();
        $rejectedCount = $project->invitations()->where('status', 'rejected')->count();

        return [
            'member_count' => $memberCount,
            'pending_invites' => $pendingCount,
            'rejected_invites' => $rejectedCount,
            'open_slots' => max(0, FypProposal::maxMembers() - $memberCount - $pendingCount),
            'members_needed' => max(0, FypProposal::minMembers() - $memberCount),
        ];
    }

    public function loadProject(Project $project): Project
    {
        $phase = $project->current_phase ?? 'proposal';
        $reviewPhase = in_array($phase, ['phase_1', 'phase_2'], true) ? $phase : 'proposal';

        return $project->load([
            'student',
            'supervisor',
            'phases.reviewer',
            'phases.reevaluator',
            'members.user',
            'invitations.invitee',
            'evaluators.evaluator',
            'evaluatorReviews' => fn ($query) => $query->where('fyp_phase', $reviewPhase)->with(['evaluator', 'answers.question']),
            'workflowLogs' => fn ($query) => $query->where('fyp_phase', $phase)->with('actor'),
            'activeSupervisorChangeRequest.currentSupervisor',
            'activeSupervisorChangeRequest.newSupervisor',
            'activeSupervisorChangeRequest.requestedBy',
        ]);
    }

    public function log(
        Project $project,
        string $stage,
        string $action,
        ?User $actor,
        ?string $role,
        ?string $comments = null,
        ?string $fypPhase = null,
    ): void {
        ProposalWorkflowLog::create([
            'project_id' => $project->id,
            'fyp_phase' => $fypPhase ?? ($project->current_phase ?? 'proposal'),
            'stage' => $stage,
            'action' => $action,
            'actor_id' => $actor?->id,
            'actor_role' => $role,
            'comments' => $comments,
        ]);

        ActivityLogService::log('workflow', 'proposals', $action, $actor?->id, $project->id);
    }

    protected function assertFacultyNotSupervisorOnProject(Project $project, int $userId, string $field = 'evaluator_ids'): void
    {
        if ($project->supervisor_id && (int) $project->supervisor_id === $userId) {
            throw ValidationException::withMessages([
                $field => [FypProposal::supervisorEvaluatorConflictMessage()],
            ]);
        }
    }

    protected function assertFacultyNotEvaluatorOnProject(Project $project, int $userId, string $field = 'supervisor_id'): void
    {
        if ($project->evaluators()->where('evaluator_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                $field => [FypProposal::supervisorEvaluatorConflictMessage()],
            ]);
        }
    }

    protected function notifyGroup(Project $project, string $title, string $message, string $type = 'info'): void
    {
        $memberIds = $project->members()->where('status', 'active')->pluck('user_id')->all();
        NotificationService::sendToMany($memberIds, $title, $message, $type, ['project_id' => $project->id]);
    }

    protected function notifyCommittee(Project $project, ?string $title = null, ?string $message = null): void
    {
        $title ??= 'New Proposal for Review';
        $message ??= "Proposal \"{$project->title}\" is ready for FYP committee review.";

        if (! $project->program_id) {
            return;
        }

        User::query()
            ->whereIn('id', $this->programScope->committeeMemberIds((int) $project->program_id))
            ->each(function ($user) use ($project, $title, $message) {
                NotificationService::send($user, $title, $message, 'info', ['project_id' => $project->id]);
            });
    }

    protected function userBelongsToProgram(User $user, int $programId): bool
    {
        if ((int) $user->program_id === $programId) {
            return true;
        }

        return $user->programMemberships()->where('program_id', $programId)->exists();
    }

    public function isProjectMember(User $user, Project $project): bool
    {
        return $project->student_id === $user->id
            || $project->members()->where('user_id', $user->id)->where('status', 'active')->exists();
    }
}
