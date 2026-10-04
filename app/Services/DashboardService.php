<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(private ProjectService $projectService) {}

    public function stats(User $user, bool $hasFullProjectList): array
    {
        $projectsQuery = Project::query();
        $phasesQuery = ProjectPhase::query();

        if (! $hasFullProjectList) {
            $this->projectService->scopeProjectsForUser($projectsQuery, $user);
            $this->scopePhasesForUser($phasesQuery, $user);
        } else {
            app(ProgramScopeService::class)->scopeProjects($projectsQuery, $user);
            $this->scopePhasesForUserByProgram($phasesQuery, $user);
        }

        $isDashboardAdmin = $user->hasAnyRole(config('fyp.dashboard_admin_roles', []));
        $usersQuery = User::query();
        if ($isDashboardAdmin && ! app(ProgramScopeService::class)->isGlobalAdmin($user)) {
            app(ProgramScopeService::class)->scopeUsers($usersQuery, $user);
        }

        $pendingReview = (clone $projectsQuery)
            ->where('status', 'active')
            ->whereEffectiveWorkflowStage([
                'supervisor_review',
                'committee_review',
                'evaluator_review',
                'committee_final',
                'committee_head_approval',
            ])
            ->count();

        return [
            'total_users' => $isDashboardAdmin ? $usersQuery->count() : 0,
            'total_projects' => (clone $projectsQuery)->count(),
            'active_projects' => (clone $projectsQuery)->where('status', 'active')->count(),
            'completed_projects' => (clone $projectsQuery)->where('status', 'completed')->count(),
            'pending_reviews' => $pendingReview,
            'proposal_count' => (clone $projectsQuery)->where('current_phase', 'proposal')->count(),
            'phase_1_count' => (clone $projectsQuery)->where('current_phase', 'phase_1')->count(),
            'phase_2_count' => (clone $projectsQuery)->where('current_phase', 'phase_2')->count(),
        ];
    }

    public function charts(User $user, bool $hasFullProjectList): array
    {
        $projectsQuery = Project::query();
        $phasesQuery = ProjectPhase::query();

        if (! $hasFullProjectList) {
            $this->projectService->scopeProjectsForUser($projectsQuery, $user);
            $this->scopePhasesForUser($phasesQuery, $user);
        } else {
            app(ProgramScopeService::class)->scopeProjects($projectsQuery, $user);
            $this->scopePhasesForUserByProgram($phasesQuery, $user);
        }

        $phaseBreakdown = (clone $projectsQuery)
            ->select('current_phase', DB::raw('COUNT(*) as total'))
            ->groupBy('current_phase')
            ->pluck('total', 'current_phase');

        $statusBreakdown = (clone $phasesQuery)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $projectStatusBreakdown = (clone $projectsQuery)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'phase_breakdown' => $phaseBreakdown,
            'submission_status_breakdown' => $statusBreakdown,
            'project_status_breakdown' => $projectStatusBreakdown,
        ];
    }

    public function nextActions(User $user): array
    {
        $actions = [];

        if ($user->hasRole('student')) {
            $pendingInvites = ProjectInvitation::query()
                ->where('invitee_id', $user->id)
                ->where('status', 'pending')
                ->count();

            if ($pendingInvites > 0) {
                $actions[] = [
                    'key' => 'student_invitations',
                    'title' => 'Respond to group invitations',
                    'description' => "{$pendingInvites} pending invitation(s) waiting for your response.",
                    'route' => 'my-project',
                    'priority' => 'high',
                    'count' => $pendingInvites,
                    'icon' => 'bi bi-envelope-open',
                ];
            }

            $project = $this->projectService->findStudentProject($user);

            if ($project) {
                $project->loadMissing('phases');
            }

            if ($project && (int) $project->student_id === (int) $user->id) {
                $incomingJoinCount = app(StudentTransferService::class)
                    ->pendingLeaderDecisionsForProject($project)
                    ->count();

                if ($incomingJoinCount > 0) {
                    $actions[] = [
                        'key' => 'incoming_transfer_join_requests',
                        'title' => 'Review requests to join your group',
                        'description' => "{$incomingJoinCount} student(s) requested to join your project team.",
                        'route' => 'my-project',
                        'priority' => 'high',
                        'count' => $incomingJoinCount,
                        'icon' => 'bi bi-person-plus',
                    ];
                }
            }

            if (! $project) {
                $actions[] = [
                    'key' => 'student_register',
                    'title' => 'Submit your proposal',
                    'description' => 'Create your group, invite members, and choose a supervisor.',
                    'route' => 'my-project',
                    'priority' => 'high',
                    'icon' => 'bi bi-file-earmark-plus',
                ];
            } else {
                $stage = $project->effectiveWorkflowStage();
                $isDeliverable = FypPhases::isDeliverablePhase($project->current_phase);

                if ($stage === 'draft') {
                    $actions[] = [
                        'key' => 'student_submit',
                        'title' => $isDeliverable ? 'Submit your phase deliverable' : 'Finish and submit proposal',
                        'description' => $isDeliverable
                            ? 'Upload your deliverable and submit it for supervisor review.'
                            : 'Complete your proposal details and submit for review.',
                        'route' => 'my-project',
                        'priority' => 'high',
                        'icon' => 'bi bi-send',
                    ];
                } elseif ($stage === 'revision_required') {
                    $actions[] = [
                        'key' => 'student_revision',
                        'title' => 'Resubmit after revision',
                        'description' => $isDeliverable
                            ? 'Review feedback and resubmit your updated deliverable.'
                            : 'Review feedback and resubmit your updated proposal.',
                        'route' => 'my-project',
                        'priority' => 'high',
                        'icon' => 'bi bi-arrow-repeat',
                    ];
                } elseif (! $isDeliverable && in_array($stage, ['supervisor_pending', 'group_confirmed'], true)) {
                    $actions[] = [
                        'key' => 'student_wait_supervisor',
                        'title' => 'Waiting for supervisor response',
                        'description' => 'Your supervisor has not accepted supervision yet.',
                        'route' => 'my-project',
                        'priority' => 'normal',
                        'icon' => 'bi bi-hourglass-split',
                    ];
                }
            }
        }

        if ($user->hasRole('supervisor')) {
            $pendingCount = Project::query()
                ->where('supervisor_id', $user->id)
                ->where('status', 'active')
                ->whereSupervisorNeedsAction()
                ->count();

            if ($pendingCount > 0) {
                $actions[] = [
                    'key' => 'supervisor_pending',
                    'title' => 'Review supervised projects',
                    'description' => "{$pendingCount} project(s) need your supervisor action.",
                    'route' => [
                        'name' => 'supervisor-projects',
                        'query' => ['needs_action' => 'supervisor'],
                    ],
                    'priority' => 'high',
                    'count' => $pendingCount,
                    'icon' => 'bi bi-folder-check',
                ];
            }

            $incomingJoinRequests = app(StudentTransferService::class)->pendingLeaderDecisionsForSupervisor($user);

            if ($incomingJoinRequests->count() > 0) {
                $actions[] = [
                    'key' => 'supervisor_incoming_transfer_requests',
                    'title' => 'Review requests to join a supervised group',
                    'description' => "{$incomingJoinRequests->count()} student(s) requested to join a group you supervise.",
                    'route' => [
                        'name' => 'project-detail',
                        'params' => ['id' => $incomingJoinRequests->first()->to_project_id],
                    ],
                    'priority' => 'high',
                    'count' => $incomingJoinRequests->count(),
                    'icon' => 'bi bi-person-plus',
                ];
            }
        }

        if ($user->hasRole('evaluator')) {
            $assignedCount = Project::query()
                ->where('status', 'active')
                ->whereHas('evaluators', fn ($q) => $q->where('evaluator_id', $user->id))
                ->whereDoesntHave(
                    'evaluatorReviews',
                    fn ($review) => $review
                        ->where('evaluator_id', $user->id)
                        ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase')
                )
                ->count();

            if ($assignedCount > 0) {
                $actions[] = [
                    'key' => 'evaluator_pending',
                    'title' => 'Complete assigned evaluations',
                    'description' => "{$assignedCount} project(s) still need your evaluation.",
                    'route' => 'evaluator-assigned-projects',
                    'priority' => 'high',
                    'count' => $assignedCount,
                    'icon' => 'bi bi-journal-check',
                ];
            }
        }

        if ($user->hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member'])
            || $user->can('manage fyp projects')
            || $user->can('manage evaluations')) {
            $projectsQuery = Project::query();
            app(ProgramScopeService::class)->scopeProjects($projectsQuery, $user);

            $committeeReviewCount = (clone $projectsQuery)
                ->where('status', 'active')
                ->whereEffectiveWorkflowStage('committee_review')
                ->count();

            $committeeFinalCount = (clone $projectsQuery)
                ->where('status', 'active')
                ->whereEffectiveWorkflowStage('committee_final')
                ->count();

            $committeeHeadCount = (clone $projectsQuery)
                ->where('status', 'active')
                ->whereEffectiveWorkflowStage('committee_head_approval')
                ->count();

            if ($committeeReviewCount > 0) {
                $actions[] = [
                    'key' => 'committee_review',
                    'title' => 'Review committee assignment queue',
                    'description' => "{$committeeReviewCount} project(s) need evaluator assignment or committee review.",
                    'route' => [
                        'name' => 'committee-workbench',
                        'query' => ['tab' => 'review'],
                    ],
                    'priority' => 'high',
                    'count' => $committeeReviewCount,
                    'icon' => 'bi bi-clipboard-data',
                ];
            }

            if ($committeeFinalCount > 0) {
                $actions[] = [
                    'key' => 'committee_final',
                    'title' => 'Complete committee final reviews',
                    'description' => "{$committeeFinalCount} project(s) are waiting for committee final decision.",
                    'route' => [
                        'name' => 'committee-workbench',
                        'query' => ['tab' => 'final'],
                    ],
                    'priority' => 'high',
                    'count' => $committeeFinalCount,
                    'icon' => 'bi bi-clipboard-check',
                ];
            }

            if ($committeeHeadCount > 0 && $user->hasRole('fyp-committee-head')) {
                $actions[] = [
                    'key' => 'committee_head',
                    'title' => 'Approve forwarded projects',
                    'description' => "{$committeeHeadCount} project(s) are waiting for committee head approval.",
                    'route' => [
                        'name' => 'committee-workbench',
                        'query' => ['tab' => 'head'],
                    ],
                    'priority' => 'high',
                    'count' => $committeeHeadCount,
                    'icon' => 'bi bi-shield-check',
                ];
            }

            if (FypProposal::canDecideSupervisorChange($user) || FypProposal::canManageSupervisorInvitations($user)) {
                $supervisorChangeCount = app(SupervisorChangeService::class)->listActiveRequestsForUser($user)->count();

                if ($supervisorChangeCount > 0) {
                    $actions[] = [
                        'key' => 'supervisor_change_requests',
                        'title' => 'Review supervisor change requests',
                        'description' => "{$supervisorChangeCount} supervisor change request(s) awaiting a response or decision.",
                        'route' => 'admin-supervisor-change-requests',
                        'priority' => 'high',
                        'count' => $supervisorChangeCount,
                        'icon' => 'bi bi-arrow-left-right',
                    ];
                }
            }

            if (FypProposal::canDecideStudentTransfer($user)) {
                $transferCount = app(StudentTransferService::class)->decidableCountForUser($user);

                if ($transferCount > 0) {
                    $actions[] = [
                        'key' => 'student_transfer_requests',
                        'title' => 'Review student transfer requests',
                        'description' => "{$transferCount} student transfer request(s) awaiting a decision.",
                        'route' => 'admin-transfer-requests',
                        'priority' => 'high',
                        'count' => $transferCount,
                        'icon' => 'bi bi-people',
                    ];
                }
            }

            if (FypProposal::canViewSupervisorOverview($user)) {
                $actions[] = [
                    'key' => 'supervisor_overview',
                    'title' => 'Check supervisor workload',
                    'description' => 'Open supervisor overview and drill into supervised projects.',
                    'route' => 'admin-supervisors',
                    'priority' => 'normal',
                    'icon' => 'bi bi-person-badge',
                ];
            }

            if (FypProposal::canAssignEvaluators($user)) {
                $actions[] = [
                    'key' => 'evaluator_overview',
                    'title' => 'Check evaluator workload',
                    'description' => 'Open evaluator overview and assigned project lists.',
                    'route' => 'admin-evaluators',
                    'priority' => 'normal',
                    'icon' => 'bi bi-person-check',
                ];

                $assignmentQuery = Project::query();
                app(ProgramScopeService::class)->scopeProjects($assignmentQuery, $user);
                $assignmentService = app(AutoEvaluatorAssignmentService::class);
                $assignmentPending = 0;
                foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
                    $assignmentPending += $assignmentService->phaseStats($assignmentQuery, $phase)['pending'];
                }

                if ($assignmentPending > 0) {
                    $actions[] = [
                        'key' => 'evaluator_assignment_pending',
                        'title' => 'Assign evaluators',
                        'description' => "{$assignmentPending} project(s) still need evaluators assigned.",
                        'route' => 'admin-evaluator-assignment',
                        'priority' => 'high',
                        'count' => $assignmentPending,
                        'icon' => 'bi bi-diagram-3',
                    ];
                }
            }
        }

        $unread = Notification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        if ($unread > 0) {
            $actions[] = [
                'key' => 'notifications',
                'title' => 'Read notifications',
                'description' => "{$unread} unread notification(s) in your inbox.",
                'path' => '/notifications',
                'priority' => 'normal',
                'count' => $unread,
                'icon' => 'bi bi-bell',
            ];
        }

        return $actions;
    }

    public function roleWidgets(User $user): array
    {
        $widgets = [];

        if ($user->hasRole('supervisor')) {
            $active = Project::query()
                ->where('supervisor_id', $user->id)
                ->where('status', 'active')
                ->count();

            $pending = Project::query()
                ->where('supervisor_id', $user->id)
                ->where('status', 'active')
                ->whereSupervisorNeedsAction()
                ->count();

            $widgets[] = [
                'key' => 'supervised_total',
                'label' => 'Active Supervised Projects',
                'value' => (string) $active,
                'hint' => "{$pending} need your action",
                'route' => [
                    'name' => 'supervisor-projects',
                    'query' => $pending > 0 ? ['needs_action' => 'supervisor'] : [],
                ],
                'link_label' => 'Open supervised projects',
            ];
        }

        if ($user->hasRole('evaluator')) {
            $assigned = Project::query()
                ->where('status', 'active')
                ->whereHas('evaluators', fn ($q) => $q->where('evaluator_id', $user->id))
                ->whereDoesntHave(
                    'evaluatorReviews',
                    fn ($review) => $review
                        ->where('evaluator_id', $user->id)
                        ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase')
                )
                ->count();

            $completed = Project::query()
                ->where('status', 'active')
                ->whereHas(
                    'evaluatorReviews',
                    fn ($review) => $review
                        ->where('evaluator_id', $user->id)
                        ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase')
                )
                ->count();

            $widgets[] = [
                'key' => 'evaluator_pending',
                'label' => 'Pending Evaluations',
                'value' => (string) $assigned,
                'hint' => "{$completed} completed in current phase",
                'route' => 'evaluator-assigned-projects',
                'link_label' => 'Open assigned projects',
            ];
        }

        if ($user->hasAnyRole(config('fyp.dashboard_admin_roles', []))) {
            $projectsQuery = Project::query();
            app(ProgramScopeService::class)->scopeProjects($projectsQuery, $user);

            $committeeFinalCount = (clone $projectsQuery)
                ->where('status', 'active')
                ->whereEffectiveWorkflowStage('committee_final')
                ->count();

            $committeeHeadCount = (clone $projectsQuery)
                ->where('status', 'active')
                ->whereEffectiveWorkflowStage('committee_head_approval')
                ->count();

            $widgets[] = [
                'key' => 'committee_pending',
                'label' => 'Committee Final Queue',
                'value' => (string) $committeeFinalCount,
                'hint' => $committeeHeadCount > 0
                    ? "{$committeeHeadCount} awaiting committee head approval"
                    : 'Projects awaiting committee final review',
                'route' => [
                    'name' => 'committee-workbench',
                    'query' => ['tab' => 'final'],
                ],
                'link_label' => 'Open committee workbench',
            ];

            if ($user->hasRole('fyp-committee-head')) {
                $widgets[] = [
                    'key' => 'committee_head_pending',
                    'label' => 'Head Approval Queue',
                    'value' => (string) $committeeHeadCount,
                    'hint' => 'Projects forwarded for committee head decision',
                    'route' => [
                        'name' => 'committee-workbench',
                        'query' => ['tab' => 'head'],
                    ],
                    'link_label' => 'Open head approval queue',
                ];
            }

            $widgets[] = [
                'key' => 'proposal_phase',
                'label' => 'Proposal Phase Projects',
                'value' => (string) (clone $projectsQuery)->where('current_phase', 'proposal')->count(),
                'hint' => 'Currently in proposal lifecycle',
                'route' => [
                    'name' => 'projects',
                    'query' => ['phase' => 'proposal'],
                ],
                'link_label' => 'View proposal projects',
            ];

            if (FypProposal::canAssignEvaluators($user)) {
                $assignmentService = app(AutoEvaluatorAssignmentService::class);
                $totals = ['total' => 0, 'completed' => 0, 'pending' => 0];

                foreach (['proposal', 'phase_1', 'phase_2'] as $phase) {
                    $stats = $assignmentService->phaseStats($projectsQuery, $phase);
                    $totals['total'] += $stats['total'];
                    $totals['completed'] += $stats['completed'];
                    $totals['pending'] += $stats['pending'];
                }

                $widgets[] = [
                    'key' => 'evaluator_assignment',
                    'label' => 'Evaluators Assigned',
                    'value' => "{$totals['completed']}/{$totals['total']}",
                    'hint' => "{$totals['pending']} project(s) pending evaluator assignment",
                    'route' => 'admin-evaluator-assignment',
                    'link_label' => 'Open evaluator assignment',
                ];
            }
        }

        return $widgets;
    }

    protected function scopePhasesForUser($query, User $user): void
    {
        if ($user->hasRole('student')) {
            $query->whereHas('project', function ($q) use ($user) {
                $q->where(function ($inner) use ($user) {
                    $inner->where('student_id', $user->id)
                        ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('status', 'active'));
                });
            });
        } elseif ($user->hasRole('supervisor')) {
            $query->whereHas('project', fn ($q) => $q->where('supervisor_id', $user->id));
        } elseif ($user->hasRole('evaluator')) {
            $query->whereHas('project', fn ($q) => $q->whereHas(
                'evaluators',
                fn ($e) => $e->where('evaluator_id', $user->id)
            ));
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    protected function scopePhasesForUserByProgram($query, User $user): void
    {
        $programScope = app(ProgramScopeService::class);
        $accessible = $programScope->accessibleProgramIds($user);

        if ($accessible === null) {
            return;
        }

        if ($accessible === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('project', fn ($project) => $programScope->scopeProjects($project, $user));
    }
}
