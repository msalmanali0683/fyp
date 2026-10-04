<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProposalSession;
use App\Models\ProposalSessionExtension;
use App\Models\User;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposalSessionService
{
    public function lifecycleLabel(string $phase): string
    {
        return match ($phase) {
            ProposalSession::LIFECYCLE_PROPOSAL_PHASE => 'Proposal Phase',
            ProposalSession::LIFECYCLE_PHASE_1 => 'Phase 1',
            ProposalSession::LIFECYCLE_PHASE_2 => 'Phase 2',
            default => ucwords(str_replace('_', ' ', $phase)),
        };
    }

    public function __construct(
        private ProgramScopeService $programScope,
        private ProjectPhaseTransitionService $phaseTransitionService,
        private SessionReportService $sessionReportService,
    ) {}

    public function listForUser(User $actor): Collection
    {
        $query = ProposalSession::query()
            ->with(['program.department', 'creator:id,name'])
            ->withCount('projects')
            ->latest('id');

        $accessible = $this->programScope->accessibleProgramIds($actor);

        if ($accessible === null) {
            return $query->get();
        }

        if ($accessible === []) {
            return collect();
        }

        return $query->whereIn('program_id', $accessible)->get();
    }

    public function optionsForUser(User $actor, ?int $programId = null, bool $includeAll = false): Collection
    {
        $query = ProposalSession::query()
            ->with('program:id,name')
            ->latest('id');

        $accessible = $this->programScope->accessibleProgramIds($actor);

        if ($accessible === []) {
            return collect();
        }

        if ($accessible !== null) {
            $query->whereIn('program_id', $accessible);
        }

        if ($programId) {
            $query->where('program_id', $programId);
        }

        if (! $includeAll) {
            $query->where('status', 'active')
                ->where('lifecycle_phase', ProposalSession::LIFECYCLE_PROPOSAL_PHASE);
        }

        return $query->get()->map(fn (ProposalSession $session) => [
            'id' => $session->id,
            'name' => $session->name,
            'code' => $session->code,
            'program_id' => $session->program_id,
            'program_name' => $session->program?->name,
            'status' => $session->status,
            'lifecycle_phase' => $session->lifecycle_phase,
            'lifecycle_phase_label' => $this->lifecycleLabel($session->lifecycle_phase),
            'is_current_proposal_session' => $this->isCurrentProposalSession($session),
        ]);
    }

    /**
     * The single active proposal-phase session for a program.
     * Rule: status=active, lifecycle_phase=proposal_phase, latest id wins.
     */
    public function currentProposalSessionForProgram(int $programId): ?ProposalSession
    {
        return ProposalSession::query()
            ->where('program_id', $programId)
            ->where('status', 'active')
            ->where('lifecycle_phase', ProposalSession::LIFECYCLE_PROPOSAL_PHASE)
            ->latest('id')
            ->first();
    }

    public function isCurrentProposalSession(ProposalSession $session): bool
    {
        if ($session->lifecycle_phase !== ProposalSession::LIFECYCLE_PROPOSAL_PHASE
            || $session->status !== 'active') {
            return false;
        }

        $current = $this->currentProposalSessionForProgram((int) $session->program_id);

        return $current && (int) $current->id === (int) $session->id;
    }

    public function assertProposalPhaseSession(ProposalSession $session): void
    {
        if ($session->lifecycle_phase !== ProposalSession::LIFECYCLE_PROPOSAL_PHASE) {
            throw ValidationException::withMessages([
                'session' => ['This action only applies while the session is in Proposal Phase.'],
            ]);
        }
    }

    public function assertManagedLifecycleSession(ProposalSession $session): void
    {
        if (! in_array($session->lifecycle_phase, [
            ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            ProposalSession::LIFECYCLE_PHASE_1,
            ProposalSession::LIFECYCLE_PHASE_2,
        ], true)) {
            throw ValidationException::withMessages([
                'session' => ['This session lifecycle does not support submission management.'],
            ]);
        }
    }

    public function assertSessionLifecycle(ProposalSession $session, string ...$allowedPhases): void
    {
        if (! in_array($session->lifecycle_phase, $allowedPhases, true)) {
            throw ValidationException::withMessages([
                'session' => ['This action is not available for the current session lifecycle phase.'],
            ]);
        }
    }

    public function isInitialDeadlinePending(ProposalSession $session): bool
    {
        $session = $this->syncSubmissionOpenState($session);
        $initial = $this->sessionDeadlinePair($session)['initial'];

        return (bool) ($initial && $session->is_submission_open && now()->lt($initial));
    }

    protected function sessionDeadlinePair(ProposalSession $session): array
    {
        return match ($session->lifecycle_phase) {
            ProposalSession::LIFECYCLE_PHASE_1 => [
                'initial' => $session->phase_1_initial_deadline,
                'final' => $session->phase_1_final_lock_deadline,
            ],
            ProposalSession::LIFECYCLE_PHASE_2 => [
                'initial' => $session->phase_2_initial_deadline,
                'final' => $session->phase_2_final_lock_deadline,
            ],
            default => [
                'initial' => $session->initial_draft_deadline,
                'final' => $session->final_lock_deadline,
            ],
        };
    }

    public function studentsForSessionQuery(ProposalSession $session): Builder
    {
        return User::query()
            ->role('student')
            ->where('status', 'active')
            ->where('program_id', $session->program_id)
            ->whereRaw('LOWER(session) = ?', [strtolower($session->code)]);
    }

    public function applyStudentSessionScope(Builder $query, ProposalSession $session): Builder
    {
        return $query
            ->where('program_id', $session->program_id)
            ->whereRaw('LOWER(session) = ?', [strtolower($session->code)]);
    }

    public function activeSessionForUser(User $user): ?ProposalSession
    {
        return $this->currentProposalSessionForUser($user);
    }

    public function currentProposalSessionForUser(User $user): ?ProposalSession
    {
        if (! $user->program_id) {
            return null;
        }

        $query = ProposalSession::query()
            ->where('program_id', $user->program_id)
            ->where('status', 'active')
            ->where('lifecycle_phase', ProposalSession::LIFECYCLE_PROPOSAL_PHASE);

        if ($user->session) {
            $query->whereRaw('LOWER(code) = ?', [strtolower($user->session)]);
        }

        return $query->latest('id')->first();
    }

    public function sessionForProject(Project $project): ?ProposalSession
    {
        if ($project->proposal_session_id) {
            return ProposalSession::find($project->proposal_session_id);
        }

        if ($project->relationLoaded('student') && $project->student) {
            return $this->activeSessionForUser($project->student);
        }

        if ($project->student_id) {
            $student = User::find($project->student_id);

            return $student ? $this->activeSessionForUser($student) : null;
        }

        return null;
    }

    public function extensionFor(ProposalSession $session, User $user): ?ProposalSessionExtension
    {
        return ProposalSessionExtension::query()
            ->where('proposal_session_id', $session->id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function effectiveInitialDeadline(ProposalSession $session, ?User $user = null): ?Carbon
    {
        $deadline = $this->sessionDeadlinePair($session)['initial'];

        if ($user) {
            $extension = $this->extensionFor($session, $user)?->extended_initial_deadline;
            if ($extension && (! $deadline || $extension->gt($deadline))) {
                $deadline = $extension;
            }
        }

        return $deadline;
    }

    public function effectiveFinalDeadline(ProposalSession $session, ?User $user = null): ?Carbon
    {
        $deadline = $this->sessionDeadlinePair($session)['final'];

        if ($user) {
            $extension = $this->extensionFor($session, $user)?->extended_final_deadline;
            if ($extension && (! $deadline || $extension->gt($deadline))) {
                $deadline = $extension;
            }
        }

        return $deadline;
    }

    public function isFullyLocked(ProposalSession $session, ?User $user = null): bool
    {
        if ($session->is_fully_locked) {
            return true;
        }

        $finalDeadline = $this->effectiveFinalDeadline($session, $user);

        return $finalDeadline ? now()->gte($finalDeadline) : false;
    }

    public function isInitialSubmissionClosed(ProposalSession $session, ?User $user = null): bool
    {
        $session = $this->syncSubmissionOpenState($session);

        if ($this->isFullyLocked($session, $user)) {
            return true;
        }

        $initialDeadline = $this->effectiveInitialDeadline($session, $user);

        if ($initialDeadline && now()->lt($initialDeadline)) {
            if ($session->is_submission_open) {
                return false;
            }

            return ! $this->userHasExtendedInitialWindow($session, $user);
        }

        return true;
    }

    public function syncAllExpiredSubmissionStates(): int
    {
        $closed = 0;
        $now = now();

        $sessions = ProposalSession::query()
            ->where('is_submission_open', true)
            ->whereIn('lifecycle_phase', [
                ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
                ProposalSession::LIFECYCLE_PHASE_1,
                ProposalSession::LIFECYCLE_PHASE_2,
            ])
            ->get();

        foreach ($sessions as $session) {
            $initial = $this->sessionDeadlinePair($session)['initial'];

            if ($initial && $now->gte($initial)) {
                ProposalSession::query()
                    ->whereKey($session->id)
                    ->update(['is_submission_open' => false]);
                $closed++;
            }
        }

        return $closed;
    }

    public function syncSubmissionOpenState(ProposalSession $session): ProposalSession
    {
        if (! in_array($session->lifecycle_phase, [
            ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            ProposalSession::LIFECYCLE_PHASE_1,
            ProposalSession::LIFECYCLE_PHASE_2,
        ], true)) {
            return $session;
        }

        $initial = $this->sessionDeadlinePair($session)['initial'];

        if (! $session->is_submission_open || ! $initial) {
            return $session;
        }

        if (now()->gte($initial)) {
            ProposalSession::query()
                ->whereKey($session->id)
                ->update(['is_submission_open' => false]);

            return $session->fresh();
        }

        return $session;
    }

    public function canManuallyCloseSubmissions(ProposalSession $session): bool
    {
        $session = $this->syncSubmissionOpenState($session);

        if (! $session->is_submission_open) {
            return false;
        }

        if (! $session->initial_draft_deadline) {
            return true;
        }

        $initial = $this->sessionDeadlinePair($session)['initial'];

        return $initial ? now()->gte($initial) : true;
    }

    protected function userHasExtendedInitialWindow(ProposalSession $session, User $user): bool
    {
        $extension = $this->extensionFor($session, $user)?->extended_initial_deadline;

        if (! $extension || ! now()->lt($extension)) {
            return false;
        }

        $sessionInitial = $this->sessionDeadlinePair($session)['initial'];

        return ! $sessionInitial || $extension->gt($sessionInitial);
    }

    public function userMatchesSession(User $user, ProposalSession $session): bool
    {
        if ((int) $user->program_id !== (int) $session->program_id) {
            return false;
        }

        if (! $session->code) {
            return true;
        }

        return $user->session
            && strtolower($user->session) === strtolower($session->code);
    }

    public function canSubmitNewProposal(User $user): bool
    {
        if (! $user->hasRole('student') || $user->status !== 'active' || ! $user->is_proposal_enrolled) {
            return false;
        }

        $session = $this->activeSessionForUser($user);
        if (! $session || ! $this->userMatchesSession($user, $session)) {
            return false;
        }

        if ($session->lifecycle_phase !== ProposalSession::LIFECYCLE_PROPOSAL_PHASE) {
            return false;
        }

        $session = $this->syncSubmissionOpenState($session);

        return ! $this->isInitialSubmissionClosed($session, $user);
    }

    public function canEditExistingProject(User $user, Project $project, bool $allowStaffBypass = true): bool
    {
        if ($allowStaffBypass && (FypProposal::canManageProposalSessions($user) || FypProposal::canManageProjectTeam($user))) {
            return true;
        }

        if (($project->current_phase ?? 'proposal') !== 'proposal') {
            return true;
        }

        $session = $this->sessionForProject($project);
        if (! $session) {
            return true;
        }

        if ($this->isFullyLocked($session, $user)) {
            return false;
        }

        return $this->isViewerProjectParticipant($user, $project);
    }

    public function canAcceptProjectInvitation(User $user, Project $project): bool
    {
        if (($project->current_phase ?? 'proposal') !== 'proposal') {
            return true;
        }

        $session = $this->sessionForProject($project);
        if (! $session) {
            return true;
        }

        return ! $this->isFullyLocked($session, $user);
    }

    public function assertCanAcceptProjectInvitation(User $user, Project $project): void
    {
        if ($this->canAcceptProjectInvitation($user, $project)) {
            return;
        }

        $session = $this->sessionForProject($project);

        throw ValidationException::withMessages([
            'session' => [
                $session
                    ? 'All proposal changes are locked for '.$session->name.'.'
                    : 'Proposal changes are currently locked.',
            ],
        ]);
    }

    protected function isViewerProjectParticipant(User $user, Project $project): bool
    {
        if ((int) $project->student_id === (int) $user->id) {
            return true;
        }

        return $project->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    public function assertCanSubmitNewProposal(User $user): void
    {
        if (! $user->hasRole('student') || $user->status !== 'active') {
            throw ValidationException::withMessages([
                'student' => ['Your account is not eligible to register a proposal.'],
            ]);
        }

        if (! $user->is_proposal_enrolled) {
            throw ValidationException::withMessages([
                'session' => ['You are not enrolled for proposal submission in the current session. Contact the FYP office.'],
            ]);
        }

        $session = $this->activeSessionForUser($user);
        if (! $session) {
            throw ValidationException::withMessages([
                'session' => ['No active proposal session is available for your program.'],
            ]);
        }

        if (! $this->userMatchesSession($user, $session)) {
            throw ValidationException::withMessages([
                'session' => ['Your student session does not match the active proposal session ('.$session->name.').'],
            ]);
        }

        if ($this->isFullyLocked($session, $user)) {
            throw ValidationException::withMessages([
                'session' => ['All proposal submissions are locked for this session.'],
            ]);
        }

        if ($this->isInitialSubmissionClosed($session, $user)) {
            $initialDeadline = $this->effectiveInitialDeadline($session, $user);

            if ($initialDeadline && now()->gte($initialDeadline)) {
                throw ValidationException::withMessages([
                    'session' => ['The initial draft submission deadline has passed for '.$session->name.'.'],
                ]);
            }

            throw ValidationException::withMessages([
                'session' => ['Proposal submissions are closed for '.$session->name.'.'],
            ]);
        }
    }

    public function assertCanEditProject(User $actor, Project $project): void
    {
        if ($this->canEditExistingProject($actor, $project)) {
            return;
        }

        $session = $this->sessionForProject($project);

        throw ValidationException::withMessages([
            'session' => [
                $session
                    ? 'All proposal changes are locked for '.$session->name.'.'
                    : 'Proposal changes are currently locked.',
            ],
        ]);
    }

    public function studentPortalContext(User $user): array
    {
        $project = $this->studentProjectForUser($user);
        $session = $this->resolveStudentPortalSession($user, $project);

        if (! $session) {
            return [
                'session' => null,
                'phase' => 'none',
                'countdown_label' => null,
                'countdown_target' => null,
                'seconds_remaining' => null,
                'can_submit_new' => false,
                'can_edit_project' => $project ? $this->canEditExistingProject($user, $project, false) : false,
                'is_submission_open' => false,
                'is_fully_locked' => false,
                'block_reason' => 'no_active_session',
            ];
        }

        $session = $this->syncSubmissionOpenState($session);

        $countdown = $this->countdownState($session, $user);
        $canSubmit = $this->canSubmitNewProposal($user)
            && ! Project::where('student_id', $user->id)->exists()
            && ! $this->userInActiveGroup($user);

        // The session's own lifecycle phase (what's "now open" for the cohort as a
        // whole) can run ahead of a specific student's project: a project left
        // pending when the FYP office completes a phase stays at its own
        // current_phase until it's individually approved or carried forward into a
        // later session (see ProjectPhaseTransitionService). Editability must key
        // off the student's own phase, not the session's, or a student stuck behind
        // would be wrongly locked out of a deliverable that's still genuinely theirs
        // to edit.
        $sessionDeliverablePhase = match ($session->lifecycle_phase) {
            ProposalSession::LIFECYCLE_PHASE_1 => 'phase_1',
            ProposalSession::LIFECYCLE_PHASE_2 => 'phase_2',
            default => 'proposal',
        };
        $studentPhase = $project?->current_phase ?? 'proposal';
        $currentDeliverablePhase = in_array($studentPhase, ['phase_1', 'phase_2'], true)
            ? $studentPhase
            : ($sessionDeliverablePhase !== 'proposal' ? $sessionDeliverablePhase : null);

        $phaseRank = ['proposal' => 0, 'phase_1' => 1, 'phase_2' => 2];
        $isAwaitingNextPhase = $project
            && ($phaseRank[$studentPhase] ?? 0) < ($phaseRank[$sessionDeliverablePhase] ?? 0);

        $awaitingNextPhaseMessage = $isAwaitingNextPhase
            ? sprintf(
                'Your %s is still being finalized. %s has opened for this session — you will move in once your outcome is confirmed. You can still view your previous phase details below.',
                FypPhases::label($studentPhase),
                $this->lifecycleLabel($session->lifecycle_phase)
            )
            : null;

        $phaseStarted = $project && $currentDeliverablePhase
            ? $this->phaseTransitionService->hasDeliverableStarted($project, $currentDeliverablePhase)
            : false;

        return [
            'session' => $this->serializeSession($session),
            'phase' => $countdown['phase'],
            'countdown_label' => $countdown['label'],
            'countdown_target' => $countdown['target']?->toDateTimeString(),
            'seconds_remaining' => $countdown['seconds_remaining'],
            'can_submit_new' => $canSubmit,
            'can_edit_project' => $project
                ? $this->canEditExistingProject($user, $project, false)
                : false,
            'can_edit_phase_deliverable' => $project && $currentDeliverablePhase
                ? $this->canEditPhaseDeliverable($user, $project, $currentDeliverablePhase)
                : false,
            'deliverable_phase' => $currentDeliverablePhase,
            'phase_deliverable_started' => $phaseStarted,
            'is_submission_open' => (bool) $session->is_submission_open,
            'is_fully_locked' => $this->isFullyLocked($session, $user),
            'has_individual_extension' => $this->userHasExtendedInitialWindow($session, $user)
                || (bool) $this->extensionFor($session, $user)?->extended_final_deadline,
            'initial_deadline' => $this->effectiveInitialDeadline($session, $user)?->toDateTimeString(),
            'final_deadline' => $this->effectiveFinalDeadline($session, $user)?->toDateTimeString(),
            'block_reason' => $this->resolveBlockReason($user, $session, $canSubmit, $project),
            'student_phase' => $studentPhase,
            'awaiting_next_phase' => $isAwaitingNextPhase,
            'awaiting_next_phase_message' => $awaitingNextPhaseMessage,
            'submission_kind_label' => match ($sessionDeliverablePhase) {
                'phase_1' => 'Phase 1 submission window',
                'phase_2' => 'Phase 2 submission window',
                default => 'New registration',
            },
        ];
    }

    public function canEditPhaseDeliverable(User $user, Project $project, string $phase): bool
    {
        if (! in_array($phase, ['phase_1', 'phase_2'], true)) {
            return false;
        }

        if ($project->current_phase !== $phase) {
            return false;
        }

        if ((int) $project->student_id !== (int) $user->id) {
            return false;
        }

        $session = $this->sessionForProject($project);
        if (! $session) {
            return true;
        }

        if ($this->isFullyLocked($session, $user)) {
            return false;
        }

        if (! $session->is_submission_open && ! $this->userHasExtendedInitialWindow($session, $user)) {
            return false;
        }

        $row = $project->phases()->where('phase', $phase)->first();
        if ($row && ! $row->isEditable()) {
            return false;
        }

        return true;
    }

    public function assertCanEditPhaseDeliverable(User $user, Project $project, string $phase): void
    {
        if ($this->canEditPhaseDeliverable($user, $project, $phase)) {
            return;
        }

        $session = $this->sessionForProject($project);

        throw ValidationException::withMessages([
            'session' => [
                $session
                    ? 'Phase deliverable changes are locked for '.$session->name.'.'
                    : 'Phase deliverable changes are currently locked.',
            ],
        ]);
    }

    protected function userInActiveGroup(User $user): bool
    {
        return ProjectMember::query()
            ->activeMembership()
            ->where('user_id', $user->id)
            ->exists();
    }

    public function studentProjectForUser(User $user): ?Project
    {
        $ownedProject = Project::query()
            ->where('student_id', $user->id)
            ->latest('id')
            ->first();

        if ($ownedProject) {
            return $ownedProject;
        }

        $membership = ProjectMember::query()
            ->activeMembership()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return $membership?->project;
    }

    /**
     * All active team member ids for the student's project (leader + active members),
     * so a deadline extension applies to the whole team rather than a single member.
     * Falls back to just the student themselves if they have no project yet.
     */
    protected function teamUserIdsFor(User $student): Collection
    {
        $project = $this->studentProjectForUser($student);

        if (! $project) {
            return collect([$student->id]);
        }

        return $project->members()
            ->where('status', 'active')
            ->pluck('user_id')
            ->push($project->student_id)
            ->filter()
            ->unique()
            ->values();
    }

    protected function resolveStudentPortalSession(User $user, ?Project $project = null): ?ProposalSession
    {
        if ($project?->proposal_session_id) {
            $projectSession = ProposalSession::find($project->proposal_session_id);
            if ($projectSession && $this->userCanViewSessionPortal($user, $project, $projectSession)) {
                return $projectSession;
            }
        }

        $session = $this->activeSessionForUser($user);

        if ($session) {
            return $session;
        }

        if ($project) {
            $projectSession = $this->sessionForProject($project);
            if ($projectSession && $this->userCanViewSessionPortal($user, $project, $projectSession)) {
                return $projectSession;
            }
        }

        return null;
    }

    protected function userCanViewSessionPortal(User $user, Project $project, ProposalSession $session): bool
    {
        if ((int) $session->program_id !== (int) $user->program_id) {
            return false;
        }

        if ($this->isViewerProjectParticipant($user, $project)) {
            return true;
        }

        return $this->userMatchesSession($user, $session);
    }

    protected function resolveBlockReason(User $user, ProposalSession $session, bool $canSubmit, ?Project $project): ?string
    {
        if ($project) {
            $deliverablePhase = match ($session->lifecycle_phase) {
                ProposalSession::LIFECYCLE_PHASE_1 => 'phase_1',
                ProposalSession::LIFECYCLE_PHASE_2 => 'phase_2',
                default => null,
            };

            if ($deliverablePhase && $project->current_phase === $deliverablePhase) {
                return $this->canEditPhaseDeliverable($user, $project, $deliverablePhase) ? null : 'project_locked';
            }

            return $this->canEditExistingProject($user, $project, false) ? null : 'project_locked';
        }

        if ($canSubmit) {
            return null;
        }

        if (! $this->userMatchesSession($user, $session)) {
            return 'session_mismatch';
        }

        if ($this->isFullyLocked($session, $user)) {
            return 'fully_locked';
        }

        if ($this->isInitialSubmissionClosed($session, $user)) {
            $initialDeadline = $this->effectiveInitialDeadline($session, $user);

            if ($initialDeadline && now()->gte($initialDeadline)) {
                return 'initial_deadline_passed';
            }

            return 'submissions_closed';
        }

        if (! $user->is_proposal_enrolled) {
            return 'not_enrolled';
        }

        return null;
    }

    public function countdownState(ProposalSession $session, ?User $user = null): array
    {
        if ($this->isFullyLocked($session, $user)) {
            return [
                'phase' => 'locked',
                'label' => 'All proposal changes are locked',
                'target' => null,
                'seconds_remaining' => 0,
            ];
        }

        $initial = $this->effectiveInitialDeadline($session, $user);
        $final = $this->effectiveFinalDeadline($session, $user);
        $now = now();

        if ($this->shouldShowInitialCountdown($session, $user, $initial, $now)) {
            $initialLabel = match ($session->lifecycle_phase) {
                ProposalSession::LIFECYCLE_PHASE_1 => 'Phase 1 start deadline — upload at least one file',
                ProposalSession::LIFECYCLE_PHASE_2 => 'Phase 2 start deadline — upload at least one file',
                default => 'Initial draft deadline — new project registration',
            };

            return [
                'phase' => 'initial_draft',
                'label' => $initialLabel,
                'target' => $initial,
                'seconds_remaining' => max(0, $now->diffInSeconds($initial, false)),
            ];
        }

        if ($final && $now->lt($final)) {
            $finalLabel = match ($session->lifecycle_phase) {
                ProposalSession::LIFECYCLE_PHASE_1 => 'Phase 1 final lock deadline',
                ProposalSession::LIFECYCLE_PHASE_2 => 'Phase 2 final lock deadline',
                default => 'Final lock deadline — existing projects',
            };

            return [
                'phase' => 'final_edits',
                'label' => $finalLabel,
                'target' => $final,
                'seconds_remaining' => max(0, $now->diffInSeconds($final, false)),
            ];
        }

        if ($initial && $now->gte($initial)) {
            return [
                'phase' => 'final_edits',
                'label' => match ($session->lifecycle_phase) {
                    ProposalSession::LIFECYCLE_PHASE_1 => 'Phase 1 final lock deadline passed',
                    ProposalSession::LIFECYCLE_PHASE_2 => 'Phase 2 final lock deadline passed',
                    default => 'Final lock deadline passed',
                },
                'target' => null,
                'seconds_remaining' => 0,
            ];
        }

        if (! $session->is_submission_open) {
            return [
                'phase' => 'final_edits',
                'label' => match ($session->lifecycle_phase) {
                    ProposalSession::LIFECYCLE_PHASE_1 => 'Phase 1 submissions closed — awaiting final lock deadline',
                    ProposalSession::LIFECYCLE_PHASE_2 => 'Phase 2 submissions closed — awaiting final lock deadline',
                    default => 'Submissions closed — awaiting final lock deadline',
                },
                'target' => null,
                'seconds_remaining' => null,
            ];
        }

        return [
            'phase' => 'open',
            'label' => $session->is_submission_open
                ? 'New project registration open'
                : 'New project registration closed',
            'target' => null,
            'seconds_remaining' => null,
        ];
    }

    protected function shouldShowInitialCountdown(
        ProposalSession $session,
        ?User $user,
        ?Carbon $initial,
        Carbon $now,
    ): bool {
        if (! $initial || ! $now->lt($initial)) {
            return false;
        }

        if ($user && $this->userHasExtendedInitialWindow($session, $user)) {
            return true;
        }

        return (bool) $session->is_submission_open;
    }

    public function serializeSession(ProposalSession $session): array
    {
        return [
            'id' => $session->id,
            'program_id' => $session->program_id,
            'program_name' => $session->program?->name,
            'name' => $session->name,
            'code' => $session->code,
            'is_submission_open' => $session->is_submission_open,
            'is_fully_locked' => $session->is_fully_locked,
            'status' => $session->status,
            'lifecycle_phase' => $session->lifecycle_phase,
            'lifecycle_phase_label' => $this->lifecycleLabel($session->lifecycle_phase),
            'is_current_proposal_session' => $this->isCurrentProposalSession($session),
            'initial_draft_deadline' => $session->initial_draft_deadline?->toIso8601String(),
            'final_lock_deadline' => $session->final_lock_deadline?->toIso8601String(),
            'phase_1_initial_deadline' => $session->phase_1_initial_deadline?->toIso8601String(),
            'phase_1_final_lock_deadline' => $session->phase_1_final_lock_deadline?->toIso8601String(),
            'phase_1_completed_at' => $session->phase_1_completed_at?->toIso8601String(),
            'phase_2_initial_deadline' => $session->phase_2_initial_deadline?->toIso8601String(),
            'phase_2_final_lock_deadline' => $session->phase_2_final_lock_deadline?->toIso8601String(),
            'phase_2_completed_at' => $session->phase_2_completed_at?->toIso8601String(),
            'can_manually_close_submissions' => $this->canManuallyCloseSubmissions($session),
            'initial_deadline_pending' => $this->isInitialDeadlinePending($session),
            'notes' => $session->notes,
            'projects_count' => $session->projects_count ?? $session->projects()->count(),
            'students_count' => $session->students_count ?? null,
        ];
    }

    protected function archiveOtherProposalPhaseSessions(int $programId, ?int $exceptId = null): void
    {
        ProposalSession::query()
            ->where('program_id', $programId)
            ->where('lifecycle_phase', ProposalSession::LIFECYCLE_PROPOSAL_PHASE)
            ->where('status', 'active')
            ->when($exceptId, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->update([
                'status' => 'archived',
                'is_submission_open' => false,
                'is_fully_locked' => true,
            ]);
    }

    public function createSession(User $actor, array $data): ProposalSession
    {
        $this->programScope->assertCanManageProgram($actor, (int) $data['program_id']);

        $this->archiveOtherProposalPhaseSessions((int) $data['program_id']);

        $session = ProposalSession::create([
            'program_id' => $data['program_id'],
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'is_submission_open' => (bool) ($data['is_submission_open'] ?? false),
            'initial_draft_deadline' => $data['initial_draft_deadline'] ?? null,
            'final_lock_deadline' => $data['final_lock_deadline'] ?? null,
            'is_fully_locked' => (bool) ($data['is_fully_locked'] ?? false),
            'status' => 'active',
            'lifecycle_phase' => ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            'created_by' => $actor->id,
            'notes' => $data['notes'] ?? null,
        ]);

        $carried = $this->phaseTransitionService->carryForwardPendingProposalProjects($session);

        if ($carried > 0) {
            ActivityLogService::log(
                'update',
                'proposal_sessions',
                "Carried forward {$carried} pending proposal(s) from a previous session into {$session->name}.",
                $actor->id
            );
        }

        return $session;
    }

    public function updateSession(User $actor, ProposalSession $session, array $data): ProposalSession
    {
        $this->programScope->assertCanManageProgram($actor, (int) $session->program_id);

        if (($data['status'] ?? $session->status) === 'active' && $session->status !== 'active') {
            ProposalSession::query()
                ->where('program_id', $session->program_id)
                ->where('status', 'active')
                ->where('id', '!=', $session->id)
                ->update(['status' => 'archived']);
        }

        $session->update([
            'name' => $data['name'] ?? $session->name,
            'code' => isset($data['code']) ? strtoupper($data['code']) : $session->code,
            'initial_draft_deadline' => array_key_exists('initial_draft_deadline', $data)
                ? $data['initial_draft_deadline']
                : $session->initial_draft_deadline,
            'final_lock_deadline' => array_key_exists('final_lock_deadline', $data)
                ? $data['final_lock_deadline']
                : $session->final_lock_deadline,
            'phase_1_initial_deadline' => array_key_exists('phase_1_initial_deadline', $data)
                ? $data['phase_1_initial_deadline']
                : $session->phase_1_initial_deadline,
            'phase_1_final_lock_deadline' => array_key_exists('phase_1_final_lock_deadline', $data)
                ? $data['phase_1_final_lock_deadline']
                : $session->phase_1_final_lock_deadline,
            'phase_2_initial_deadline' => array_key_exists('phase_2_initial_deadline', $data)
                ? $data['phase_2_initial_deadline']
                : $session->phase_2_initial_deadline,
            'phase_2_final_lock_deadline' => array_key_exists('phase_2_final_lock_deadline', $data)
                ? $data['phase_2_final_lock_deadline']
                : $session->phase_2_final_lock_deadline,
            'notes' => $data['notes'] ?? $session->notes,
            'status' => $data['status'] ?? $session->status,
        ]);

        return $session->fresh(['program.department', 'creator']);
    }

    public function setSubmissionOpen(ProposalSession $session, bool $open): ProposalSession
    {
        $this->assertManagedLifecycleSession($session);

        $session = $this->syncSubmissionOpenState($session);

        if (! $open) {
            if (! $this->canManuallyCloseSubmissions($session)) {
                throw ValidationException::withMessages([
                    'session' => ['Submissions cannot be closed manually while the initial draft deadline is still pending. They will close automatically when the deadline passes.'],
                ]);
            }

            $session->update(['is_submission_open' => false]);

            return $session->fresh();
        }

        $initial = $this->sessionDeadlinePair($session)['initial'];

        if ($initial && now()->gte($initial)) {
            throw ValidationException::withMessages([
                'session' => ['The initial draft deadline has already passed, so submissions cannot be reopened as-is. Extend the deadline to a future date/time in "Extend Session Deadlines" below, then try opening submissions again.'],
            ]);
        }

        $session->update(['is_submission_open' => true]);

        return $session->fresh();
    }

    public function setFullyLocked(ProposalSession $session, bool $locked): ProposalSession
    {
        $this->assertManagedLifecycleSession($session);

        $session->update(['is_fully_locked' => $locked]);

        return $session->fresh();
    }

    public function extendDeadlines(ProposalSession $session, array $data): ProposalSession
    {
        $this->assertManagedLifecycleSession($session);

        if ($session->lifecycle_phase === ProposalSession::LIFECYCLE_PROPOSAL_PHASE
            && ! $this->isCurrentProposalSession($session)) {
            throw ValidationException::withMessages([
                'session' => ['Extensions apply to the current active proposal session for this program. Select the highlighted session or manage the current one.'],
            ]);
        }

        $updates = ['is_fully_locked' => false];

        if ($session->lifecycle_phase === ProposalSession::LIFECYCLE_PHASE_1) {
            if (array_key_exists('initial_draft_deadline', $data)) {
                $updates['phase_1_initial_deadline'] = $data['initial_draft_deadline'];
            }
            if (array_key_exists('final_lock_deadline', $data)) {
                $updates['phase_1_final_lock_deadline'] = $data['final_lock_deadline'];
            }
        } elseif ($session->lifecycle_phase === ProposalSession::LIFECYCLE_PHASE_2) {
            if (array_key_exists('initial_draft_deadline', $data)) {
                $updates['phase_2_initial_deadline'] = $data['initial_draft_deadline'];
            }
            if (array_key_exists('final_lock_deadline', $data)) {
                $updates['phase_2_final_lock_deadline'] = $data['final_lock_deadline'];
            }
        } else {
            $updates['initial_draft_deadline'] = $data['initial_draft_deadline'] ?? $session->initial_draft_deadline;
            $updates['final_lock_deadline'] = $data['final_lock_deadline'] ?? $session->final_lock_deadline;
        }

        $session->update($updates);

        return $session->fresh();
    }

    public function generateSessionReports(ProposalSession $session, ?User $actor = null, ?string $lifecyclePhase = null): array
    {
        $phase = $lifecyclePhase ?? $session->lifecycle_phase;

        if (! in_array($phase, [
            ProposalSession::LIFECYCLE_PROPOSAL_PHASE,
            ProposalSession::LIFECYCLE_PHASE_1,
            ProposalSession::LIFECYCLE_PHASE_2,
        ], true)) {
            throw ValidationException::withMessages([
                'lifecycle_phase' => 'Reports can only be generated for an active proposal, Phase 1, or Phase 2 session.',
            ]);
        }

        $reports = $this->sessionReportService->generateRepositoryReports($session, $phase, $actor);

        ActivityLogService::log(
            'create',
            'proposal_session_reports',
            "Generated session repository reports for {$session->name} ({$this->lifecycleLabel($phase)}).",
            $actor?->id
        );

        return $reports;
    }

    public function completeProposalPhase(ProposalSession $session, ?User $actor = null): array
    {
        $this->assertProposalPhaseSession($session);

        return DB::transaction(function () use ($session, $actor) {
            $reports = $this->generateSessionReports(
                $session,
                $actor,
                ProposalSession::LIFECYCLE_PROPOSAL_PHASE
            );

            // Non-approved projects and their students are no longer deleted here — they
            // stay put and are automatically carried into the program's next
            // proposal-phase session (see createSession() ->
            // carryForwardPendingProposalProjects()), so no proposal needs to be
            // resubmitted from scratch.
            $pendingKept = $this->countPendingProposalProjects($session);
            $unplacedStudents = $this->countUnplacedStudentsForSession($session);

            $approvedKept = Project::query()
                ->where('proposal_session_id', $session->id)
                ->where('workflow_stage', 'approved')
                ->count();

            $projectsPrepared = $this->phaseTransitionService->prepareApprovedProjectsForPhase1($session);
            $repeatsCarriedForward = $this->phaseTransitionService->carryForwardRepeatingPhase1Projects($session);

            $session->update([
                'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_1,
                'is_submission_open' => true,
                'is_fully_locked' => false,
            ]);

            ActivityLogService::log(
                'update',
                'proposal_sessions',
                "Completed proposal phase for {$session->name}: kept {$approvedKept} approved project(s), prepared {$projectsPrepared} for Phase 1, carried forward {$repeatsCarriedForward} repeating Phase-1 project(s), {$pendingKept} pending project(s) and {$unplacedStudents} unplaced student(s) will carry forward into the next proposal session.",
                $actor?->id
            );

            return [
                'session' => $session->fresh(),
                'reports' => $reports,
                'summary' => [
                    'approved_projects_kept' => $approvedKept,
                    'projects_prepared_for_phase_1' => $projectsPrepared,
                    'phase_1_repeats_carried_forward' => $repeatsCarriedForward,
                    'pending_projects_kept' => $pendingKept,
                    'unplaced_students_kept' => $unplacedStudents,
                ],
            ];
        });
    }

    public function completePhase1(ProposalSession $session, ?User $actor = null): array
    {
        $this->assertSessionLifecycle($session, ProposalSession::LIFECYCLE_PHASE_1);

        return DB::transaction(function () use ($session, $actor) {
            $reports = $this->generateSessionReports(
                $session,
                $actor,
                ProposalSession::LIFECYCLE_PHASE_1
            );

            $advanced = $this->phaseTransitionService->advanceApprovedPhase1ProjectsToPhase2($session);
            $pending = $this->phaseTransitionService->countPendingPhase1Projects($session);
            $repeatsCarriedForward = $this->phaseTransitionService->carryForwardRepeatingPhase2Projects($session);

            $session->update([
                'lifecycle_phase' => ProposalSession::LIFECYCLE_PHASE_2,
                'phase_1_completed_at' => now(),
                'is_submission_open' => true,
                'is_fully_locked' => false,
            ]);

            ActivityLogService::log(
                'update',
                'proposal_sessions',
                "Completed Phase 1 for {$session->name}: advanced {$advanced} approved project(s) to Phase 2, carried forward {$repeatsCarriedForward} repeating Phase-2 project(s), {$pending} project(s) remain pending for a future session.",
                $actor?->id
            );

            return [
                'session' => $session->fresh(),
                'reports' => $reports,
                'summary' => [
                    'phase_1_approved_advanced' => $advanced,
                    'phase_2_repeats_carried_forward' => $repeatsCarriedForward,
                    'phase_1_pending_kept' => $pending,
                ],
            ];
        });
    }

    public function completePhase2(ProposalSession $session, ?User $actor = null): array
    {
        $this->assertSessionLifecycle($session, ProposalSession::LIFECYCLE_PHASE_2);

        return DB::transaction(function () use ($session, $actor) {
            $reports = $this->generateSessionReports(
                $session,
                $actor,
                ProposalSession::LIFECYCLE_PHASE_2
            );

            $completed = $this->phaseTransitionService->completeApprovedPhase2Projects($session);
            $pending = $this->phaseTransitionService->countPendingPhase2Projects($session);

            $session->update([
                'phase_2_completed_at' => now(),
                'is_submission_open' => false,
                'is_fully_locked' => true,
                'status' => 'archived',
            ]);

            ActivityLogService::log(
                'update',
                'proposal_sessions',
                "Completed Phase 2 for {$session->name}: marked {$completed} project(s) completed, {$pending} project(s) remain pending for a future session.",
                $actor?->id
            );

            return [
                'session' => $session->fresh(),
                'reports' => $reports,
                'summary' => [
                    'phase_2_approved_completed' => $completed,
                    'phase_2_pending_kept' => $pending,
                ],
            ];
        });
    }

    /**
     * Non-approved projects and unplaced students are no longer deleted when the proposal
     * phase completes — they're left as-is and picked up automatically by
     * ProjectPhaseTransitionService::carryForwardPendingProposalProjects() once the
     * program's next proposal-phase session is created. This just reports the counts so
     * the admin can see the impact before confirming, and again afterward.
     */
    public function countPendingProposalProjects(ProposalSession $session): int
    {
        return $this->pendingProjectsForSession($session)->count();
    }

    public function countUnplacedStudentsForSession(ProposalSession $session): int
    {
        return $this->studentsForSessionQuery($session)
            ->get()
            ->filter(fn (User $student) => $student->hasRole('student') && ! $this->studentHasApprovedProjectInSession($student, $session))
            ->count();
    }

    /**
     * What completing the proposal phase would affect, for a pre-confirmation preview —
     * no writes happen here.
     */
    public function previewCompleteProposalPhase(ProposalSession $session): array
    {
        $pendingProjects = $this->pendingProjectsForSession($session);

        return [
            'approved_count' => Project::query()
                ->where('proposal_session_id', $session->id)
                ->where('workflow_stage', 'approved')
                ->count(),
            'pending_projects_count' => $pendingProjects->count(),
            'pending_projects' => $pendingProjects->map(fn (Project $project) => [
                'id' => $project->id,
                'title' => $project->title,
                'leader_name' => $project->student?->name,
            ])->values(),
            'unplaced_students_count' => $this->countUnplacedStudentsForSession($session),
        ];
    }

    protected function pendingProjectsForSession(ProposalSession $session): Collection
    {
        $sessionStudentIds = $this->studentsForSessionQuery($session)->pluck('id');

        return Project::withTrashed()
            ->with('student:id,name')
            ->where('workflow_stage', '!=', 'approved')
            ->where(function (Builder $query) use ($session, $sessionStudentIds) {
                $query->where('proposal_session_id', $session->id);

                if ($sessionStudentIds->isNotEmpty()) {
                    $query->orWhere(function (Builder $inner) use ($session, $sessionStudentIds) {
                        $inner->whereIn('student_id', $sessionStudentIds)
                            ->where('current_phase', 'proposal')
                            ->where(function (Builder $legacy) use ($session) {
                                $legacy->whereNull('proposal_session_id')
                                    ->orWhere('proposal_session_id', $session->id);
                            });
                    });
                }
            })
            ->get()
            ->unique('id')
            ->values();
    }

    protected function studentHasApprovedProjectInSession(User $student, ProposalSession $session): bool
    {
        return Project::query()
            ->where('proposal_session_id', $session->id)
            ->where('workflow_stage', 'approved')
            ->where(function (Builder $query) use ($student) {
                $query->where('student_id', $student->id)
                    ->orWhereHas('members', fn (Builder $member) => $member
                        ->where('user_id', $student->id)
                        ->where('status', 'active'));
            })
            ->exists();
    }

    public function grantUserExtension(
        User $actor,
        ProposalSession $session,
        User $student,
        array $data,
    ): Collection {
        $this->assertProposalPhaseSession($session);

        if ((int) $student->program_id !== (int) $session->program_id) {
            throw ValidationException::withMessages([
                'user_id' => ['The selected student is not in this session\'s program.'],
            ]);
        }

        if (! $this->userMatchesSession($student, $session)) {
            throw ValidationException::withMessages([
                'user_id' => ['The selected student is not enrolled in this proposal session.'],
            ]);
        }

        $teamUserIds = $this->teamUserIdsFor($student);

        return DB::transaction(fn () => $teamUserIds->map(fn (int $userId) => ProposalSessionExtension::updateOrCreate(
            [
                'proposal_session_id' => $session->id,
                'user_id' => $userId,
            ],
            [
                'extended_initial_deadline' => $data['extended_initial_deadline'] ?? null,
                'extended_final_deadline' => $data['extended_final_deadline'] ?? null,
                'reason' => $data['reason'] ?? null,
                'granted_by' => $actor->id,
            ]
        )));
    }

    public function revokeUserExtension(ProposalSession $session, User $student): void
    {
        $teamUserIds = $this->teamUserIdsFor($student);

        ProposalSessionExtension::query()
            ->where('proposal_session_id', $session->id)
            ->whereIn('user_id', $teamUserIds)
            ->delete();
    }
}
