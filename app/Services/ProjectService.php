<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\EffectiveWorkflow;
use App\Support\FypPermissions;
use App\Support\FypPhases;
use App\Support\FypProposal;
use App\Support\FypRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function __construct(
        private ProposalWorkflowService $workflowService,
        private ProgramScopeService $programScope,
        private ProposalSessionService $sessionService,
        private PhaseDeliverableWorkflowService $phaseWorkflowService,
    ) {}

    public function createProject(User $student, array $data): Project
    {
        if ($student->project()->exists()) {
            throw ValidationException::withMessages([
                'project' => ['You already have an FYP project registered.'],
            ]);
        }

        return DB::transaction(function () use ($student, $data) {
            $project = Project::create([
                'student_id' => $student->id,
                'program_id' => $student->program_id,
                'supervisor_id' => $data['supervisor_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'academic_year' => $data['academic_year'] ?? now()->format('Y').'-'.(now()->year + 1),
                'current_phase' => 'proposal',
                'status' => 'active',
            ]);

            foreach (FypPhases::slugs() as $phase) {
                ProjectPhase::create([
                    'project_id' => $project->id,
                    'phase' => $phase,
                    'status' => $phase === 'proposal' ? 'draft' : 'draft',
                ]);
            }

            return $project->load(['student', 'supervisor', 'phases.reviewer']);
        });
    }

    public function isPhaseUnlocked(Project $project, string $phase): bool
    {
        if ($phase === 'proposal') {
            return true;
        }

        $previous = FypPhases::previousPhase($phase);

        if (! $previous) {
            return false;
        }

        $previousPhase = $project->phase($previous);

        return $previousPhase && $previousPhase->status === 'approved';
    }

    public function updatePhaseContent(ProjectPhase $phase, array $data): ProjectPhase
    {
        if (! $this->isPhaseUnlocked($phase->project, $phase->phase)) {
            throw ValidationException::withMessages([
                'phase' => ['This phase is locked until the previous phase is approved.'],
            ]);
        }

        if (! $phase->isEditable()) {
            throw ValidationException::withMessages([
                'phase' => ['This phase cannot be edited in its current status.'],
            ]);
        }

        $this->sessionService->assertCanEditPhaseDeliverable(
            auth()->user() ?? $phase->project->student,
            $phase->project,
            $phase->phase
        );

        $phase->update([
            'content' => $data['content'] ?? $phase->content,
            'attachment' => $data['attachment'] ?? $phase->attachment,
        ]);

        return $phase->fresh()->load(['reviewer', 'project']);
    }

    public function submitPhase(ProjectPhase $phase): ProjectPhase
    {
        if (! $this->isPhaseUnlocked($phase->project, $phase->phase)) {
            throw ValidationException::withMessages([
                'phase' => ['This phase is locked until the previous phase is approved.'],
            ]);
        }

        if (! in_array($phase->status, ['draft', 'revision_required'], true)) {
            throw ValidationException::withMessages([
                'phase' => ['Only draft or revision-required submissions can be submitted.'],
            ]);
        }

        $requiresAttachment = in_array($phase->phase, ['phase_1', 'phase_2'], true);

        if ($requiresAttachment && blank($phase->attachment) && blank($phase->content)) {
            throw ValidationException::withMessages([
                'attachment' => ['Upload at least one file to start this phase.'],
            ]);
        }

        if (! $requiresAttachment && blank($phase->content)) {
            throw ValidationException::withMessages([
                'content' => ['Please add submission content before submitting.'],
            ]);
        }

        $this->sessionService->assertCanEditPhaseDeliverable(
            auth()->user() ?? $phase->project->student,
            $phase->project,
            $phase->phase
        );

        if (FypPhases::isDeliverablePhase($phase->phase)) {
            $workflowStage = $phase->workflow_stage ?? 'draft';

            if ($workflowStage === 'revision_required') {
                throw ValidationException::withMessages([
                    'phase' => ['Use the resubmit action to send your revised deliverable for review.'],
                ]);
            }

            if ($workflowStage !== 'draft') {
                throw ValidationException::withMessages([
                    'phase' => ['This deliverable has already been submitted and is under review.'],
                ]);
            }

            return $this->phaseWorkflowService->markSubmitted(
                $phase->fresh(),
                auth()->user() ?? $phase->project->student
            );
        }

        $phase->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'feedback' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        $this->workflowService->log(
            $phase->project,
            $phase->phase,
            ucfirst(str_replace('_', ' ', $phase->phase)).' deliverable submitted',
            auth()->user(),
            auth()->user()?->hasRole('student') ? 'student' : 'system',
            null,
            $phase->phase
        );

        return $phase->fresh()->load(['reviewer', 'project']);
    }

    public function reviewPhase(ProjectPhase $phase, User $reviewer, string $status, ?string $feedback = null): ProjectPhase
    {
        if (FypPhases::isDeliverablePhase($phase->phase)) {
            throw ValidationException::withMessages([
                'phase' => ['Use the phase workflow review endpoints for deliverable phases.'],
            ]);
        }

        if (! in_array($status, ['under_review', 'approved', 'revision_required', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid review status.'],
            ]);
        }

        if (! in_array($phase->status, ['submitted', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'phase' => ['Only submitted phases can be reviewed.'],
            ]);
        }

        $phase->update([
            'status' => $status,
            'feedback' => $feedback,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
        ]);

        $project = $phase->project;

        if ($status === 'approved') {
            if ($phase->phase === 'proposal') {
                $nextPhase = FypPhases::nextPhase($phase->phase);

                if ($nextPhase) {
                    $project->update(['current_phase' => $nextPhase]);
                }
            }
        }

        $this->workflowService->log(
            $project,
            $phase->phase,
            ucfirst(str_replace('_', ' ', $phase->phase)).' review: '.$status,
            $reviewer,
            $reviewer->getRoleNames()->first(),
            $feedback,
            $phase->phase
        );

        return $phase->fresh()->load(['reviewer', 'project.student', 'project.supervisor']);
    }

    public function canAccessProject(User $user, Project $project): bool
    {
        if ($project->student_id === $user->id) {
            return true;
        }

        if ($project->members()->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return true;
        }

        if ($user->hasRole('supervisor') && $project->supervisor_id === $user->id) {
            return true;
        }

        if ($user->hasRole('evaluator') && $project->evaluators()->where('evaluator_id', $user->id)->exists()) {
            return true;
        }

        if ($user->hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member'])) {
            return $this->programScope->canAccessProject($user, $project);
        }

        foreach (FypPermissions::projectAccessPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function canReviewPhase(User $user, Project $project): bool
    {
        if (! $user->hasAnyRole(FypPhases::reviewRoles())) {
            return false;
        }

        if ($user->hasRole('supervisor')) {
            return $project->supervisor_id === $user->id;
        }

        return true;
    }

    public function canListProjects(User $user): bool
    {
        if ($user->hasAnyRole(FypRoles::fullProjectAccessRoles())) {
            return true;
        }

        foreach (FypPermissions::projectAccessPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasFullProjectListAccess(User $user): bool
    {
        if ($user->hasAnyRole(FypRoles::fullProjectListRoles())) {
            return true;
        }

        // Supervisors always see only their supervised projects unless they also hold staff roles above.
        if ($user->hasRole('supervisor')) {
            return false;
        }

        // Evaluators see all projects only when granted explicit list permissions.
        if ($user->hasRole('evaluator')) {
            foreach (FypRoles::fullProjectListPermissions() as $permission) {
                if ($user->can($permission)) {
                    return true;
                }
            }

            return false;
        }

        foreach (FypRoles::fullProjectListPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function findStudentProject(User $user): ?Project
    {
        if (! $user->hasRole('student')) {
            return null;
        }

        $ownedProject = Project::query()
            ->where('student_id', $user->id)
            ->latest()
            ->first();

        if ($ownedProject) {
            return $ownedProject;
        }

        $membership = ProjectMember::query()
            ->activeMembership()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        return $membership?->project;
    }

    public function scopeProjectsForUser(Builder $query, User $user, ?string $listContext = null): Builder
    {
        if ($listContext === 'supervisor') {
            return $query->where('supervisor_id', $user->id);
        }

        if ($listContext === 'evaluator') {
            return $query->whereHas('evaluators', fn ($e) => $e->where('evaluator_id', $user->id));
        }

        if ($this->hasFullProjectListAccess($user)) {
            return $this->programScope->scopeProjects($query, $user);
        }

        if ($user->hasRole('supervisor')) {
            return $query->where('supervisor_id', $user->id);
        }

        if ($user->hasRole('evaluator')) {
            return $query->whereHas('evaluators', fn ($e) => $e->where('evaluator_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }

    public function applyProjectFilters(Builder $query, Request $request, User $user): Builder
    {
        return $query
            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhereHas('student', fn ($s) => $s->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('student', fn ($s) => $s->where('registration_no', 'like', "%{$search}%"));
                });
            })
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->phase, fn ($q) => $q->where('current_phase', $request->phase))
            ->when(
                $request->workflow_stage,
                fn ($q) => $q->whereEffectiveWorkflowStage($request->workflow_stage)
            )
            ->when(
                $request->needs_action,
                fn ($q) => EffectiveWorkflow::applyNeedsMyActionFilter($q, $user, $request->needs_action)
            )
            ->when(
                $request->program_id && ($this->hasFullProjectListAccess($user) || $this->programScope->isGlobalAdmin($user)),
                fn ($q) => $q->where('program_id', $request->integer('program_id'))
            )
            ->when(
                $request->supervisor_id && $this->hasFullProjectListAccess($user),
                fn ($q) => $q->where('supervisor_id', $request->integer('supervisor_id'))
            )
            ->when(
                $request->evaluator_id && $this->hasFullProjectListAccess($user),
                fn ($q) => $q->whereHas(
                    'evaluators',
                    fn ($e) => $e->where('evaluator_id', $request->integer('evaluator_id'))
                )
            )
            ->when(
                $request->proposal_session_id && $this->hasFullProjectListAccess($user),
                fn ($q) => $q->where('proposal_session_id', $request->integer('proposal_session_id'))
            );
    }

    public function applyEvaluatorScopeFilter(Builder $query, User $user, ?string $scope, ?string $listContext = null): Builder
    {
        if ($listContext !== 'evaluator' || ! $scope || ! $user->hasRole('evaluator')) {
            return $query;
        }

        if ($scope === 'completed') {
            return $query->whereHas(
                'evaluatorReviews',
                fn ($review) => $review
                    ->where('evaluator_id', $user->id)
                    ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase')
            );
        }

        if ($scope === 'assigned') {
            return $query->whereDoesntHave(
                'evaluatorReviews',
                fn ($review) => $review
                    ->where('evaluator_id', $user->id)
                    ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase')
            );
        }

        return $query;
    }

    public function canManageProjectTeam(User $user): bool
    {
        return FypProposal::canManageProjectTeam($user);
    }

    public function canManageProjectTeamFor(User $user, Project $project): bool
    {
        return FypProposal::canManageProjectTeamFor($user, $project);
    }

    public function removeTeamMember(User $actor, Project $project, ProjectMember $member, ?string $comments = null): Project
    {
        if (! $this->canManageProjectTeamFor($actor, $project)) {
            throw ValidationException::withMessages(['member' => ['Unauthorized to remove team members.']]);
        }

        if ($member->project_id !== $project->id || $member->status !== 'active') {
            throw ValidationException::withMessages(['member' => ['Team member not found.']]);
        }

        if ($member->role === 'leader') {
            throw ValidationException::withMessages([
                'member' => ['The group leader cannot be removed. Delete the project to dissolve the team.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $project, $member, $comments) {
            $member->update(['status' => 'removed']);

            $action = "{$member->user?->name} was removed from the project team";
            $this->workflowService->log($project, 'group_confirmed', $action, $actor, 'admin', $comments);

            if ($member->user) {
                NotificationService::send(
                    $member->user_id,
                    'Removed From FYP Group',
                    $comments
                        ? "You were removed from the proposal \"{$project->title}\". Reason: {$comments}"
                        : "You were removed from the proposal \"{$project->title}\".",
                    'warning',
                    ['project_id' => $project->id]
                );
            }

            NotificationService::send(
                $project->student_id,
                'Team Member Removed',
                $comments
                    ? "{$member->user?->name} was removed from your group. Reason: {$comments}"
                    : "{$member->user?->name} was removed from your group.",
                'warning',
                ['project_id' => $project->id]
            );

            $activeCount = ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->count();

            if ($project->current_phase === 'proposal' && $activeCount < FypProposal::minMembers()) {
                $project->update(['workflow_stage' => 'invitations_pending']);
            }

            ActivityLogService::log('update', 'projects', "Removed team member from {$project->title}", $actor->id, $project->id);

            return $this->workflowService->loadProject($project->fresh());
        });
    }

    /**
     * The current leader can hand leadership to any other active team member
     * (e.g. if they're stepping back); the office/supervisor authority that
     * already manages team membership can also do it on the team's behalf.
     * Project.student_id is the single source of truth for "who leads" used
     * throughout the app, so updating it here is enough to flip every
     * leader-gated permission over to the new leader automatically.
     */
    public function transferLeadership(User $actor, Project $project, int $newLeaderId, ?string $comments = null): Project
    {
        $isCurrentLeader = (int) $project->student_id === (int) $actor->id;

        if (! $isCurrentLeader && ! $this->canManageProjectTeamFor($actor, $project)) {
            throw ValidationException::withMessages(['project' => ['Unauthorized to change the team leader.']]);
        }

        if ($project->status !== 'active') {
            throw ValidationException::withMessages(['project' => ['This project is not active.']]);
        }

        if ((int) $newLeaderId === (int) $project->student_id) {
            throw ValidationException::withMessages(['new_leader_id' => ['This student is already the team leader.']]);
        }

        $newLeaderMember = ProjectMember::query()
            ->where('project_id', $project->id)
            ->where('user_id', $newLeaderId)
            ->where('status', 'active')
            ->first();

        if (! $newLeaderMember) {
            throw ValidationException::withMessages(['new_leader_id' => ['Selected student is not an active member of this team.']]);
        }

        $previousLeaderId = $project->student_id;

        return DB::transaction(function () use ($actor, $project, $newLeaderMember, $previousLeaderId, $comments) {
            ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('user_id', $previousLeaderId)
                ->where('status', 'active')
                ->update(['role' => 'member']);

            $newLeaderMember->update(['role' => 'leader']);
            $project->update(['student_id' => $newLeaderMember->user_id]);

            $newLeader = $newLeaderMember->user;

            $this->workflowService->log(
                $project,
                'group_confirmed',
                "Team leadership transferred to {$newLeader?->name}",
                $actor,
                (int) $actor->id === (int) $previousLeaderId ? 'student' : 'admin',
                $comments
            );

            NotificationService::send(
                $newLeaderMember->user_id,
                'You Are Now the Team Leader',
                $comments
                    ? "You have been made the team leader for \"{$project->title}\". Note: {$comments}"
                    : "You have been made the team leader for \"{$project->title}\".",
                'success',
                ['project_id' => $project->id]
            );

            if ((int) $previousLeaderId !== (int) $newLeaderMember->user_id) {
                NotificationService::send(
                    $previousLeaderId,
                    'Team Leadership Transferred',
                    "{$newLeader?->name} is now the team leader for \"{$project->title}\".",
                    'info',
                    ['project_id' => $project->id]
                );
            }

            ActivityLogService::log('update', 'projects', "Transferred team leadership on {$project->title}", $actor->id, $project->id);

            return $this->workflowService->loadProject($project->fresh());
        });
    }

    /**
     * Approves every project (proposal or phase deliverable, whichever the
     * project is currently sitting in) awaiting the Committee Head's final
     * decision, within the head's own program scope, in one call — instead of
     * approving each one individually. Reuses the existing single-item
     * approval methods so every side effect (notifications, logging, stage
     * transitions) stays identical to approving one at a time.
     */
    public function committeeHeadApproveAll(User $reviewer): array
    {
        if (! $reviewer->hasRole('fyp-committee-head')) {
            throw ValidationException::withMessages(['reviewer' => ['Only Committee Head can perform final approval.']]);
        }

        $query = Project::where('status', 'active')->whereEffectiveWorkflowStage('committee_head_approval');
        $projects = $this->programScope->scopeProjects($query, $reviewer)->with('phases')->get();

        $approvedIds = [];
        $failed = [];

        foreach ($projects as $project) {
            try {
                if ($project->current_phase === 'proposal') {
                    $this->workflowService->committeeHeadApprove($reviewer, $project, true);
                } else {
                    $this->phaseWorkflowService->committeeHeadApprove($reviewer, $project, $project->current_phase, true);
                }

                $approvedIds[] = $project->id;
            } catch (ValidationException $e) {
                $failed[] = ['project_id' => $project->id, 'title' => $project->title, 'message' => $e->getMessage()];
            }
        }

        return [
            'approved_count' => count($approvedIds),
            'approved_ids' => $approvedIds,
            'failed' => $failed,
        ];
    }

    public function deleteProject(User $actor, Project $project, ?string $comments = null): void
    {
        if (! $this->canManageProjectTeam($actor)) {
            throw ValidationException::withMessages(['project' => ['Unauthorized to delete projects.']]);
        }

        DB::transaction(function () use ($actor, $project, $comments) {
            $userIds = ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->pluck('user_id')
                ->push($project->student_id)
                ->unique()
                ->values();

            ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->update(['status' => 'removed']);

            $project->invitations()->where('status', 'pending')->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'response_comments' => 'Project deleted by administrator.',
            ]);

            $this->workflowService->log(
                $project,
                'proposal_submitted',
                'Project deleted and team dissolved',
                $actor,
                'admin',
                $comments
            );

            $message = $comments
                ? "The proposal \"{$project->title}\" was deleted and your group has been dissolved. Reason: {$comments}"
                : "The proposal \"{$project->title}\" was deleted and your group has been dissolved.";

            NotificationService::sendToMany($userIds->all(), 'FYP Project Deleted', $message, 'danger', [
                'project_id' => $project->id,
            ]);

            ActivityLogService::log('delete', 'projects', "Deleted FYP project {$project->title}", $actor->id);

            Storage::disk(config('fyp.proposal.document_disk', 'public'))
                ->deleteDirectory('proposals/'.$project->id);

            $project->forceDelete();
        });
    }

    public function forceDeleteProject(User $actor, Project $project): void
    {
        if (! FypProposal::canManageDeletedProjects($actor)) {
            throw ValidationException::withMessages(['project' => ['Unauthorized to permanently delete projects.']]);
        }

        if (! $project->trashed()) {
            throw ValidationException::withMessages([
                'project' => ['Only soft-deleted projects can be permanently removed from this screen.'],
            ]);
        }

        DB::transaction(function () use ($actor, $project) {
            ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->update(['status' => 'removed']);

            Storage::disk(config('fyp.proposal.document_disk', 'public'))
                ->deleteDirectory('proposals/'.$project->id);

            $title = $project->title;
            $project->forceDelete();

            ActivityLogService::log(
                'delete',
                'projects',
                "Permanently deleted soft-deleted FYP project {$title}",
                $actor->id
            );
        });
    }

    public function canManageDeletedProjects(User $user): bool
    {
        return FypProposal::canManageDeletedProjects($user);
    }
}
