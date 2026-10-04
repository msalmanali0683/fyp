<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\AutoEvaluatorAssignmentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EvaluatorController;
use App\Http\Controllers\Api\NotificationBroadcastController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PhaseCertificateController;
use App\Http\Controllers\Api\PhaseDeliverableWorkflowController;
use App\Http\Controllers\Api\PhaseTemplateController;
use App\Http\Controllers\Api\ProgramAccessController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectPhaseController;
use App\Http\Controllers\Api\ProjectQueryController;
use App\Http\Controllers\Api\ProposalSessionController;
use App\Http\Controllers\Api\ProposalWorkflowController;
use App\Http\Controllers\Api\QuestionBankController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StudentTransferController;
use App\Http\Controllers\Api\SupervisorChangeController;
use App\Http\Controllers\Api\SupervisorController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'store']);

Route::middleware('throttle:6,1')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy']);
    Route::get('/user', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/charts', [DashboardController::class, 'charts']);
    Route::get('/dashboard/next-actions', [DashboardController::class, 'nextActions']);
    Route::get('/dashboard/role-widgets', [DashboardController::class, 'roleWidgets']);
    Route::get('/dashboard/recent-records', [DashboardController::class, 'recentRecords']);

    Route::get('/dashboard/recent-projects', [DashboardController::class, 'recentProjects']);

    Route::get('/phase-templates', [PhaseTemplateController::class, 'index']);
    Route::post('/phase-templates', [PhaseTemplateController::class, 'store']);
    Route::delete('/phase-templates/{phaseTemplate}', [PhaseTemplateController::class, 'destroy']);

    Route::get('/question-bank', [QuestionBankController::class, 'index']);
    Route::post('/question-bank', [QuestionBankController::class, 'store']);
    Route::put('/question-bank/{question}', [QuestionBankController::class, 'update']);
    Route::delete('/question-bank/{question}', [QuestionBankController::class, 'destroy']);

    Route::get('/proposals/settings', [ProposalWorkflowController::class, 'settings']);
    Route::put('/proposals/settings', [ProposalWorkflowController::class, 'updateSettings']);
    Route::get('/proposals/session-context', [ProposalSessionController::class, 'studentContext']);
    Route::get('/proposals/my-project', [ProposalWorkflowController::class, 'myProject']);
    Route::get('/proposals/eligible-members', [ProposalWorkflowController::class, 'eligibleMembers']);
    Route::get('/proposals/pending-invitations', [ProposalWorkflowController::class, 'pendingInvitations']);
    Route::get('/proposals/evaluators', [ProposalWorkflowController::class, 'evaluators']);
    Route::post('/proposals/submit', [ProposalWorkflowController::class, 'submit']);
    Route::post('/projects/{project}/invitations', [ProposalWorkflowController::class, 'inviteMembers']);
    Route::post('/projects/{project}/members/direct', [ProposalWorkflowController::class, 'addTeamMembers']);
    Route::post('/projects/{project}/transfer-leadership', [ProjectController::class, 'transferLeadership']);
    Route::delete('/projects/{project}/invitations/{invitation}', [ProposalWorkflowController::class, 'cancelInvitation']);
    Route::post('/projects/{project}/invitations/{invitation}/accept-on-behalf', [ProposalWorkflowController::class, 'acceptInvitationOnBehalf']);
    Route::post('/proposals/invitations/{invitation}/respond', [ProposalWorkflowController::class, 'respondInvitation']);
    Route::post('/projects/{project}/supervisor/respond', [ProposalWorkflowController::class, 'supervisorRespond']);
    Route::post('/projects/{project}/supervisor/request-revision', [ProposalWorkflowController::class, 'supervisorRequestRevision']);
    Route::post('/projects/{project}/supervisor/revision-review', [ProposalWorkflowController::class, 'supervisorRevisionReview']);
    Route::put('/projects/{project}/supervisor', [ProposalWorkflowController::class, 'changeSupervisor']);
    Route::get('/supervisor-change-requests', [SupervisorChangeController::class, 'index']);
    Route::get('/transfer-requests/targets', [StudentTransferController::class, 'targets']);
    Route::get('/transfer-requests', [StudentTransferController::class, 'index']);
    Route::post('/transfer-requests', [StudentTransferController::class, 'store']);
    Route::post('/transfer-requests/{transferRequest}/select-target', [StudentTransferController::class, 'selectTarget']);
    Route::post('/transfer-requests/{transferRequest}/decide', [StudentTransferController::class, 'decide']);
    Route::post('/transfer-requests/{transferRequest}/cancel', [StudentTransferController::class, 'cancel']);
    Route::post('/projects/{project}/supervisor-change', [SupervisorChangeController::class, 'store']);
    Route::post('/projects/{project}/supervisor-change/{changeRequest}/respond', [SupervisorChangeController::class, 'respond']);
    Route::post('/projects/{project}/supervisor-change/{changeRequest}/decide', [SupervisorChangeController::class, 'decide']);
    Route::post('/projects/{project}/supervisor-change/{changeRequest}/cancel', [SupervisorChangeController::class, 'cancel']);
    Route::post('/projects/{project}/evaluators', [ProposalWorkflowController::class, 'assignEvaluators']);
    Route::post('/projects/{project}/evaluator-review', [ProposalWorkflowController::class, 'evaluatorReview']);
    Route::post('/projects/{project}/resubmit', [ProposalWorkflowController::class, 'resubmit']);
    Route::post('/projects/{project}/committee-final', [ProposalWorkflowController::class, 'committeeFinal']);
    Route::post('/projects/{project}/committee-head-approve', [ProposalWorkflowController::class, 'committeeHeadApprove']);
    Route::post('/projects/{project}/return-to-team-formation', [ProposalWorkflowController::class, 'returnToTeamFormation']);
    Route::get('/projects/{project}/workflow-logs', [ProposalWorkflowController::class, 'workflowLogs']);
    Route::patch('/admin/supervisors/{user}/supervision-limits', [SupervisorController::class, 'updateSupervisionLimits']);

    Route::get('/projects/supervisors', [ProjectController::class, 'supervisors']);
    Route::get('/projects/export', [ProjectController::class, 'export']);
    Route::post('/projects/committee-head/approve-all', [ProjectController::class, 'committeeHeadApproveAll']);
    Route::delete('/projects/{project}/force', [ProjectController::class, 'forceDestroy']);
    Route::delete('/projects/{project}/members/{member}', [ProjectController::class, 'removeMember']);
    Route::apiResource('projects', ProjectController::class);
    Route::put('/projects/{project}/phases/{phase}', [ProjectPhaseController::class, 'update']);
    Route::post('/projects/{project}/phases/{phase}/attachment', [ProjectPhaseController::class, 'uploadAttachment']);
    Route::post('/projects/{project}/phases/{phase}/submit', [ProjectPhaseController::class, 'submit']);
    Route::patch('/projects/{project}/phases/{phase}/review', [ProjectPhaseController::class, 'review']);
    Route::post('/projects/{project}/phases/{phase}/supervisor/respond', [PhaseDeliverableWorkflowController::class, 'supervisorRespond']);
    Route::post('/projects/{project}/phases/{phase}/supervisor/revision-review', [PhaseDeliverableWorkflowController::class, 'supervisorRevisionReview']);
    Route::post('/projects/{project}/phases/{phase}/evaluators/keep', [PhaseDeliverableWorkflowController::class, 'keepEvaluators']);
    Route::post('/projects/{project}/phases/{phase}/evaluators', [PhaseDeliverableWorkflowController::class, 'assignEvaluators']);
    Route::post('/projects/{project}/phases/{phase}/committee-return', [PhaseDeliverableWorkflowController::class, 'returnFromCommittee']);
    Route::post('/projects/{project}/phases/{phase}/repeat/allow', [PhaseDeliverableWorkflowController::class, 'allowRepeatCarryForward']);
    Route::post('/projects/{project}/phases/{phase}/repeat/resubmit', [PhaseDeliverableWorkflowController::class, 'requireRepeatResubmission']);
    Route::post('/projects/{project}/phases/{phase}/evaluator-review', [PhaseDeliverableWorkflowController::class, 'evaluatorReview']);
    Route::post('/projects/{project}/phases/{phase}/resubmit', [PhaseDeliverableWorkflowController::class, 'resubmit']);
    Route::post('/projects/{project}/phases/{phase}/committee-final', [PhaseDeliverableWorkflowController::class, 'committeeFinal']);
    Route::post('/projects/{project}/phases/{phase}/committee-head-approve', [PhaseDeliverableWorkflowController::class, 'committeeHeadApprove']);
    Route::post('/projects/{project}/phases/{phase}/reevaluate', [PhaseDeliverableWorkflowController::class, 'reevaluate']);
    Route::get('/projects/{project}/phases/{phase}/certificate', [PhaseCertificateController::class, 'download']);

    Route::get('/queries/my-projects', [ProjectQueryController::class, 'myProjects']);
    Route::get('/queries', [ProjectQueryController::class, 'index']);
    Route::post('/queries', [ProjectQueryController::class, 'store']);
    Route::get('/queries/{projectQuery}', [ProjectQueryController::class, 'show']);
    Route::post('/queries/{projectQuery}/reply', [ProjectQueryController::class, 'reply']);
    Route::post('/queries/{projectQuery}/close', [ProjectQueryController::class, 'close']);
    Route::post('/queries/{projectQuery}/reopen', [ProjectQueryController::class, 'reopen']);
    Route::get('/queries/{projectQuery}/pdf', [ProjectQueryController::class, 'downloadPdf']);

    Route::apiResource('categories', CategoryController::class)->except(['show']);
    Route::apiResource('records', RecordController::class);
    Route::patch('/records/{record}/status', [RecordController::class, 'updateStatus']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::get('/notifications/broadcast/recipients', [NotificationBroadcastController::class, 'recipients']);
    Route::post('/notifications/broadcast', [NotificationBroadcastController::class, 'store']);

    Route::get('/programs/accessible', [ProgramController::class, 'accessible']);

    Route::get('/proposal-sessions', [ProposalSessionController::class, 'index']);
    Route::get('/proposal-sessions/options', [ProposalSessionController::class, 'options']);
    Route::get('/proposal-sessions/current', [ProposalSessionController::class, 'current']);
    Route::post('/proposal-sessions', [ProposalSessionController::class, 'store']);
    Route::get('/proposal-sessions/{proposalSession}', [ProposalSessionController::class, 'show']);
    Route::put('/proposal-sessions/{proposalSession}', [ProposalSessionController::class, 'update']);
    Route::get('/proposal-sessions/{proposalSession}/students', [ProposalSessionController::class, 'students']);
    Route::get('/proposal-sessions/{proposalSession}/students/export', [ProposalSessionController::class, 'exportStudents']);
    Route::post('/proposal-sessions/{proposalSession}/students/import', [ProposalSessionController::class, 'importStudents']);
    Route::get('/proposal-sessions/{proposalSession}/students/import/template', [ProposalSessionController::class, 'importStudentsTemplate']);
    Route::post('/proposal-sessions/{proposalSession}/generate-reports', [ProposalSessionController::class, 'generateReports']);
    Route::get('/proposal-sessions/{proposalSession}/reports/{report}/download', [ProposalSessionController::class, 'downloadReport']);
    Route::post('/proposal-sessions/{proposalSession}/open-submissions', [ProposalSessionController::class, 'openSubmissions']);
    Route::post('/proposal-sessions/{proposalSession}/close-submissions', [ProposalSessionController::class, 'closeSubmissions']);
    Route::post('/proposal-sessions/{proposalSession}/lock-all', [ProposalSessionController::class, 'lockAll']);
    Route::post('/proposal-sessions/{proposalSession}/unlock-all', [ProposalSessionController::class, 'unlockAll']);
    Route::post('/proposal-sessions/{proposalSession}/extend-deadlines', [ProposalSessionController::class, 'extendDeadlines']);
    Route::get('/proposal-sessions/{proposalSession}/complete-proposal-phase/preview', [ProposalSessionController::class, 'previewCompleteProposalPhase']);
    Route::post('/proposal-sessions/{proposalSession}/complete-proposal-phase', [ProposalSessionController::class, 'completeProposalPhase']);
    Route::post('/proposal-sessions/{proposalSession}/complete-phase-1', [ProposalSessionController::class, 'completePhase1']);
    Route::post('/proposal-sessions/{proposalSession}/complete-phase-2', [ProposalSessionController::class, 'completePhase2']);
    Route::post('/proposal-sessions/{proposalSession}/extensions', [ProposalSessionController::class, 'grantExtension']);
    Route::delete('/proposal-sessions/{proposalSession}/extensions/{user}', [ProposalSessionController::class, 'revokeExtension']);

    Route::middleware('role:admin|fyp-committee-head')->group(function () {
        Route::apiResource('departments', DepartmentController::class)->only(['index', 'store', 'update']);
        Route::apiResource('programs', ProgramController::class)->only(['index', 'store', 'update']);
        Route::get('/programs/{program}/access-grants', [ProgramAccessController::class, 'index']);
        Route::post('/programs/{program}/access-grants', [ProgramAccessController::class, 'store']);
        Route::delete('/programs/{program}/access-grants/{grant}', [ProgramAccessController::class, 'destroy']);
        Route::get('/users/faculty/import/template', [UserController::class, 'facultyImportTemplate']);
        Route::post('/users/faculty/import', [UserController::class, 'importFaculty']);
        Route::apiResource('users', UserController::class);
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::apiResource('roles', RoleController::class)->except(['show']);
    });

    Route::get('/admin/supervisors', [SupervisorController::class, 'index']);
    Route::get('/admin/supervisors/{user}', [SupervisorController::class, 'show']);
    Route::get('/admin/evaluators', [EvaluatorController::class, 'index']);
    Route::patch('/admin/evaluators/{user}/evaluation-limits', [EvaluatorController::class, 'updateEvaluationLimits']);
    Route::get('/evaluator-assignment/sessions', [AutoEvaluatorAssignmentController::class, 'sessions']);
    Route::get('/evaluator-assignment/pending', [AutoEvaluatorAssignmentController::class, 'pending']);
    Route::post('/evaluator-assignment/preview', [AutoEvaluatorAssignmentController::class, 'preview']);
    Route::post('/evaluator-assignment/auto-assign', [AutoEvaluatorAssignmentController::class, 'assign']);
    Route::get('/admin/activity-logs', [ActivityLogController::class, 'index']);
});
