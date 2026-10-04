<?php

namespace App\Support;

use App\Models\Project;
use App\Models\SupervisorChangeRequest;
use App\Models\User;
use App\Services\FypSettingsService;

class FypProposal
{
    protected static function settingsService(): FypSettingsService
    {
        return app(FypSettingsService::class);
    }

    public static function settings(): array
    {
        return config('fyp.proposal', []);
    }

    public static function minMembers(): int
    {
        return (int) self::settingsService()->proposalTeamLimits()['min_members'];
    }

    public static function maxMembers(): int
    {
        return (int) self::settingsService()->proposalTeamLimits()['max_members'];
    }

    public static function minEvaluators(): int
    {
        return (int) self::settingsService()->proposalTeamLimits()['min_evaluators'];
    }

    public static function maxEvaluators(): int
    {
        return (int) self::settingsService()->proposalTeamLimits()['max_evaluators'];
    }

    public static function workflowStages(): array
    {
        return config('fyp.proposal_workflow_stages', []);
    }

    public static function phaseDeliverableWorkflowStages(): array
    {
        return config('fyp.phase_deliverable_workflow_stages', []);
    }

    public static function displayStage(string $workflowStage): string
    {
        return config("fyp.proposal_workflow_map.{$workflowStage}", $workflowStage);
    }

    public static function displayDeliverableStage(string $workflowStage): string
    {
        return config("fyp.phase_deliverable_workflow_map.{$workflowStage}", $workflowStage);
    }

    public static function deliverableStageLabel(string $stageKey): string
    {
        foreach (self::phaseDeliverableWorkflowStages() as $stage) {
            if ($stage['key'] === $stageKey) {
                return $stage['label'];
            }
        }

        return ucwords(str_replace('_', ' ', $stageKey));
    }

    public static function stageLabel(string $stageKey): string
    {
        foreach (self::workflowStages() as $stage) {
            if ($stage['key'] === $stageKey) {
                return $stage['label'];
            }
        }

        return ucwords(str_replace('_', ' ', $stageKey));
    }

    public static function stageOrder(string $stageKey): int
    {
        foreach (self::workflowStages() as $stage) {
            if ($stage['key'] === $stageKey) {
                return (int) $stage['order'];
            }
        }

        return 0;
    }

    public static function assignEvaluatorRoles(): array
    {
        return self::settings()['assign_evaluator_roles'] ?? [];
    }

    public static function assignEvaluatorPermissions(): array
    {
        return self::settings()['assign_evaluator_permissions'] ?? [];
    }

    public static function evaluatorAssignmentStages(): array
    {
        return self::settings()['evaluator_assignment_stages'] ?? ['committee_review'];
    }

    public static function evaluatorResubmitModes(): array
    {
        return self::settings()['evaluator_resubmit_modes'] ?? [
            'all' => 'All assigned evaluators',
            'negative_only' => 'Only evaluators who rejected or requested revision',
        ];
    }

    public static function evaluatorResubmitModeLabel(?string $mode): ?string
    {
        if (! $mode) {
            return null;
        }

        return self::evaluatorResubmitModes()[$mode] ?? ucwords(str_replace('_', ' ', $mode));
    }

    public static function isValidEvaluatorResubmitMode(?string $mode): bool
    {
        return $mode && array_key_exists($mode, self::evaluatorResubmitModes());
    }

    public static function canViewEvaluatorNames(?User $user): bool
    {
        return self::canAssignEvaluators($user);
    }

    public static function evaluatorVisibilitySettings(): array
    {
        return self::settingsService()->evaluatorVisibility();
    }

    public static function evaluatorVisibilityForPhase(string $phase): array
    {
        return self::settingsService()->evaluatorVisibilityForPhase($phase);
    }

    public static function canAssignEvaluators(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::assignEvaluatorRoles())) {
            return true;
        }

        foreach (self::assignEvaluatorPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function viewSupervisorOverviewRoles(): array
    {
        return self::settings()['view_supervisor_overview_roles'] ?? [];
    }

    public static function viewSupervisorOverviewPermissions(): array
    {
        return self::settings()['view_supervisor_overview_permissions'] ?? [];
    }

    public static function canViewSupervisorOverview(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::viewSupervisorOverviewRoles())) {
            return true;
        }

        foreach (self::viewSupervisorOverviewPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canAssignEvaluatorsOnProject(?User $user, string $workflowStage): bool
    {
        if (! self::canAssignEvaluators($user)) {
            return false;
        }

        return in_array($workflowStage, self::evaluatorAssignmentStages(), true);
    }

    public static function manageTeamRoles(): array
    {
        return self::settings()['manage_team_roles'] ?? [];
    }

    public static function manageTeamPermissions(): array
    {
        return self::settings()['manage_team_permissions'] ?? [];
    }

    public static function canManageProjectTeam(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::manageTeamRoles())) {
            return true;
        }

        foreach (self::manageTeamPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin/committee-head/permission-holder can manage any project's team; a
     * supervisor can directly add/remove students only on projects they themselves supervise.
     */
    public static function canManageProjectTeamFor(?User $user, Project $project): bool
    {
        if (self::canManageProjectTeam($user)) {
            return true;
        }

        return $user
            && $user->hasRole('supervisor')
            && (int) $project->supervisor_id === (int) $user->id;
    }

    public static function returnTeamFormationRoles(): array
    {
        return self::settings()['return_team_formation_roles'] ?? [];
    }

    public static function returnTeamFormationPermissions(): array
    {
        return self::settings()['return_team_formation_permissions'] ?? ['return proposal to team formation'];
    }

    public static function canReturnToTeamFormation(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::returnTeamFormationRoles())) {
            return true;
        }

        foreach (self::returnTeamFormationPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function returnToTeamFormationStages(): array
    {
        return self::settings()['return_team_formation_blocked_stages'] ?? [
            'draft',
            'invitations_pending',
            'approved',
        ];
    }

    public static function canReturnProjectToTeamFormation(?User $user, string $workflowStage): bool
    {
        if (! self::canReturnToTeamFormation($user)) {
            return false;
        }

        return ! in_array($workflowStage, self::returnToTeamFormationStages(), true);
    }

    public static function manageDeletedProjectRoles(): array
    {
        return self::settings()['manage_deleted_project_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function manageDeletedProjectPermissions(): array
    {
        return self::settings()['manage_deleted_project_permissions'] ?? ['delete projects'];
    }

    public static function canManageDeletedProjects(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::manageDeletedProjectRoles())) {
            return true;
        }

        foreach (self::manageDeletedProjectPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function cancelInvitationRoles(): array
    {
        return self::settings()['cancel_invitation_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function cancelInvitationPermissions(): array
    {
        return self::settings()['cancel_invitation_permissions'] ?? ['cancel project invitations'];
    }

    public static function canCancelProjectInvitations(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::cancelInvitationRoles())) {
            return true;
        }

        foreach (self::cancelInvitationPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canCancelInvitation(?User $user, Project $project): bool
    {
        if (! $user || ($project->workflow_stage ?? '') !== 'invitations_pending') {
            return false;
        }

        return self::canCancelProjectInvitations($user);
    }

    public static function canAdminManageTeamFormation(?User $user, Project $project): bool
    {
        if (! $user || ($project->workflow_stage ?? '') !== 'invitations_pending') {
            return false;
        }

        return self::canManageProjectTeam($user);
    }

    public static function manageSupervisorInvitationRoles(): array
    {
        return self::settings()['manage_supervisor_invitation_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function manageSupervisorInvitationPermissions(): array
    {
        return self::settings()['manage_supervisor_invitation_permissions'] ?? ['manage supervisor invitations'];
    }

    public static function canManageSupervisorInvitations(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::manageSupervisorInvitationRoles())) {
            return true;
        }

        foreach (self::manageSupervisorInvitationPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canRespondSupervisorInvitation(?User $user, Project $project): bool
    {
        if (! $user || ($project->workflow_stage ?? '') !== 'supervisor_pending') {
            return false;
        }

        if ((int) $project->supervisor_id === (int) $user->id) {
            return true;
        }

        return self::canManageSupervisorInvitations($user);
    }

    /**
     * Same authority that can accept/decline the initial supervision invitation
     * on a supervisor's behalf also covers reviewing a resubmitted revision on
     * their behalf, since both are "act for the assigned supervisor" actions.
     * Takes the *effective* workflow stage rather than reading it off $project,
     * since a revision under a phase deliverable lives on the ProjectPhase row,
     * not on Project::workflow_stage (see ProjectResource's $workflowStage).
     */
    public static function canManageSupervisorRevisionReview(?User $user, ?int $supervisorId, string $effectiveWorkflowStage): bool
    {
        if (! $user || $effectiveWorkflowStage !== 'supervisor_revision_pending') {
            return false;
        }

        if ((int) $supervisorId === (int) $user->id) {
            return false;
        }

        return self::canManageSupervisorInvitations($user);
    }

    public static function manageEvaluatorReviewRoles(): array
    {
        return self::settings()['manage_evaluator_review_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function manageEvaluatorReviewPermissions(): array
    {
        return self::settings()['manage_evaluator_review_permissions'] ?? ['manage evaluator reviews'];
    }

    public static function canManageEvaluatorReviews(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::manageEvaluatorReviewRoles())) {
            return true;
        }

        foreach (self::manageEvaluatorReviewPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function studentTransferAuthorityRoles(): array
    {
        return self::settings()['student_transfer_authority_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function studentTransferAuthorityPermissions(): array
    {
        return self::settings()['student_transfer_authority_permissions'] ?? ['decide student transfer'];
    }

    public static function sendNotificationRoles(): array
    {
        return self::settings()['send_notification_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function sendNotificationPermissions(): array
    {
        return self::settings()['send_notification_permissions'] ?? ['send notifications'];
    }

    public static function canSendNotifications(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::sendNotificationRoles())) {
            return true;
        }

        foreach (self::sendNotificationPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function deleteNotificationRoles(): array
    {
        return self::settings()['delete_notification_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function deleteNotificationPermissions(): array
    {
        return self::settings()['delete_notification_permissions'] ?? ['delete notifications'];
    }

    public static function canDeleteNotifications(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::deleteNotificationRoles())) {
            return true;
        }

        foreach (self::deleteNotificationPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function reevaluatePhaseRoles(): array
    {
        return self::settings()['reevaluate_phase_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function reevaluatePhasePermissions(): array
    {
        return self::settings()['reevaluate_phase_permissions'] ?? ['reevaluate phase deliverable'];
    }

    public static function canReevaluatePhase(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::reevaluatePhaseRoles())) {
            return true;
        }

        foreach (self::reevaluatePhasePermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function managePhaseTemplatesRoles(): array
    {
        return self::settings()['manage_phase_templates_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function managePhaseTemplatesPermissions(): array
    {
        return self::settings()['manage_phase_templates_permissions'] ?? ['manage phase templates'];
    }

    public static function canManagePhaseTemplates(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::managePhaseTemplatesRoles())) {
            return true;
        }

        foreach (self::managePhaseTemplatesPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function manageQuestionBankRoles(): array
    {
        return self::settings()['manage_question_bank_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function manageQuestionBankPermissions(): array
    {
        return self::settings()['manage_question_bank_permissions'] ?? ['manage evaluation questions'];
    }

    public static function canManageQuestionBank(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::manageQuestionBankRoles())) {
            return true;
        }

        foreach (self::manageQuestionBankPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canDecideStudentTransfer(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::studentTransferAuthorityRoles())) {
            return true;
        }

        foreach (self::studentTransferAuthorityPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function supervisorChangeAuthorityRoles(): array
    {
        return self::settings()['supervisor_change_authority_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function supervisorChangeAuthorityPermissions(): array
    {
        return self::settings()['supervisor_change_authority_permissions'] ?? ['decide supervisor change'];
    }

    public static function canDecideSupervisorChange(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::supervisorChangeAuthorityRoles())) {
            return true;
        }

        foreach (self::supervisorChangeAuthorityPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function phaseRepeatAuthorityRoles(): array
    {
        return self::settings()['phase_repeat_authority_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function phaseRepeatAuthorityPermissions(): array
    {
        return self::settings()['phase_repeat_authority_permissions'] ?? ['decide phase repeat'];
    }

    public static function canDecidePhaseRepeat(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::phaseRepeatAuthorityRoles())) {
            return true;
        }

        foreach (self::phaseRepeatAuthorityPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function respondProjectQueriesRoles(): array
    {
        return self::settings()['respond_project_queries_roles'] ?? ['admin', 'fyp-committee-head', 'fyp-committee-member'];
    }

    public static function respondProjectQueriesPermissions(): array
    {
        return self::settings()['respond_project_queries_permissions'] ?? ['respond to project queries'];
    }

    public static function canRespondProjectQueries(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::respondProjectQueriesRoles())) {
            return true;
        }

        foreach (self::respondProjectQueriesPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function hasActiveSupervisorChangeRequest(Project $project): bool
    {
        if ($project->relationLoaded('activeSupervisorChangeRequest')) {
            return $project->activeSupervisorChangeRequest !== null;
        }

        return $project->activeSupervisorChangeRequest()->exists();
    }

    public static function canRequestSupervisorChange(?User $user, Project $project): bool
    {
        if (! $user || (int) $project->student_id !== (int) $user->id) {
            return false;
        }

        if (! $project->supervisor_id) {
            return false;
        }

        // Changing a supervisor only makes sense once one has actually confirmed
        // supervision. Before that, the leader picks a different supervisor
        // outright (see ProposalWorkflowService::changeSupervisor()) rather than
        // filing a formal change request against someone who never accepted.
        if ($project->supervisor_status !== 'accepted') {
            return false;
        }

        if (in_array($project->workflow_stage ?? '', ['draft', 'invitations_pending'], true)) {
            return false;
        }

        return ! self::hasActiveSupervisorChangeRequest($project);
    }

    public static function canRespondSupervisorChangeAsCurrent(?User $user, ?SupervisorChangeRequest $request): bool
    {
        if (! $user || ! $request || $request->overall_status !== 'pending') {
            return false;
        }

        if ($request->current_supervisor_status !== 'pending') {
            return false;
        }

        return (int) $request->current_supervisor_id === (int) $user->id;
    }

    public static function canRespondSupervisorChangeAsNew(?User $user, ?SupervisorChangeRequest $request): bool
    {
        if (! $user || ! $request || $request->overall_status !== 'pending') {
            return false;
        }

        if ($request->new_supervisor_status !== 'pending') {
            return false;
        }

        return (int) $request->new_supervisor_id === (int) $user->id;
    }

    public static function canManageSupervisorChange(?User $user, ?SupervisorChangeRequest $request): bool
    {
        if (! $user || ! $request || ! self::canManageSupervisorInvitations($user)) {
            return false;
        }

        if ($request->overall_status !== 'pending') {
            return false;
        }

        $canActForCurrent = $request->current_supervisor_status === 'pending'
            && (int) $request->current_supervisor_id !== (int) $user->id;
        $canActForNew = $request->new_supervisor_status === 'pending'
            && (int) $request->new_supervisor_id !== (int) $user->id;

        return $canActForCurrent || $canActForNew;
    }

    public static function canDecideActiveSupervisorChange(?User $user, ?SupervisorChangeRequest $request): bool
    {
        return $request
            && $request->overall_status === 'awaiting_authority'
            && self::canDecideSupervisorChange($user);
    }

    public static function supervisorEvaluatorConflictMessage(): string
    {
        return 'The same faculty member cannot be both supervisor and evaluator on the same project.';
    }

    public static function pendingEvaluatorIds(Project $project, ?string $fypPhase = null): array
    {
        $fypPhase ??= in_array($project->current_phase, ['phase_1', 'phase_2'], true)
            ? $project->current_phase
            : 'proposal';

        $reviewQuery = $project->evaluatorReviews()->where('fyp_phase', $fypPhase);
        $reviewedIds = $project->relationLoaded('evaluatorReviews')
            ? $project->evaluatorReviews->where('fyp_phase', $fypPhase)->pluck('evaluator_id')
            : $reviewQuery->pluck('evaluator_id');
        $assignedIds = $project->relationLoaded('evaluators')
            ? $project->evaluators->pluck('evaluator_id')
            : $project->evaluators()->pluck('evaluator_id');

        return $assignedIds
            ->diff($reviewedIds)
            ->when($project->supervisor_id, fn ($ids) => $ids->reject(
                fn ($id) => (int) $id === (int) $project->supervisor_id
            ))
            ->values()
            ->all();
    }

    public static function settingsRoles(): array
    {
        return self::settings()['settings_roles'] ?? [];
    }

    public static function settingsPermissions(): array
    {
        return self::settings()['settings_permissions'] ?? ['manage proposal settings'];
    }

    public static function canManageProposalSettings(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::settingsRoles())) {
            return true;
        }

        foreach (self::settingsPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function sessionManagementRoles(): array
    {
        return self::settings()['session_management_roles'] ?? ['admin', 'fyp-committee-head'];
    }

    public static function sessionManagementPermissions(): array
    {
        return self::settings()['session_management_permissions'] ?? ['manage proposal sessions'];
    }

    public static function sessionExtensionPermissions(): array
    {
        return self::settings()['session_extension_permissions'] ?? [
            'grant proposal session extensions',
            'manage proposal sessions',
        ];
    }

    public static function canManageProposalSessions(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(self::sessionManagementRoles())) {
            return true;
        }

        foreach (self::sessionManagementPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function canSelectProposalSessionForStudents(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::canManageProposalSessions($user)) {
            return true;
        }

        return $user->can('create students') || $user->can('edit students');
    }

    public static function canGrantProposalSessionExtensions(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::canManageProposalSessions($user)) {
            return true;
        }

        foreach (self::sessionExtensionPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
