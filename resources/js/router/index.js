import { createRouter, createWebHistory } from 'vue-router'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import { getBaseUrl } from '@/utils/baseUrl'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/auth/Login.vue'),
    meta: { guest: true, title: 'Login' },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/auth/ForgotPassword.vue'),
    meta: { guest: true, title: 'Forgot Password' },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('@/views/auth/ResetPassword.vue'),
    meta: { guest: true, title: 'Reset Password' },
  },
  {
    path: '/',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/views/dashboard/DashboardIndex.vue'),
        meta: { title: 'Dashboard', breadcrumb: 'Dashboard' },
      },
      {
        path: 'my-project',
        name: 'my-project',
        component: () => import('@/views/projects/MyProject.vue'),
        meta: { title: 'My Project', breadcrumb: 'My Project', requiresStudent: true },
      },
      {
        path: 'projects',
        name: 'projects',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: {
          title: 'Projects',
          breadcrumb: 'Projects',
          requiresProjectAccess: true,
          listContext: 'all',
        },
      },
      {
        path: 'admin/committee-workbench',
        name: 'committee-workbench',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: {
          title: 'Committee Workbench',
          breadcrumb: 'Committee Workbench',
          requiresProjectAccess: true,
          listContext: 'all',
          committeeWorkbench: true,
        },
      },
      {
        path: 'notifications',
        name: 'notifications',
        component: () => import('@/views/notifications/NotificationsIndex.vue'),
        meta: { title: 'Notifications', breadcrumb: 'Notifications' },
      },
      {
        path: 'notifications/send',
        name: 'send-notification',
        component: () => import('@/views/notifications/NotificationBroadcast.vue'),
        meta: { title: 'Send Notification', breadcrumb: 'Send Notification', requiresSendNotifications: true },
      },
      {
        path: 'queries',
        name: 'project-queries',
        component: () => import('@/views/queries/ProjectQueriesIndex.vue'),
        meta: { title: 'Project Queries', breadcrumb: 'Queries', requiresProjectQueryAccess: true },
      },
      {
        path: 'supervisor/projects',
        name: 'supervisor-projects',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: {
          title: 'Supervised Projects',
          breadcrumb: 'Supervised Projects',
          requiresProjectAccess: true,
          listContext: 'supervisor',
        },
      },
      {
        path: 'evaluator/assigned-projects',
        name: 'evaluator-assigned-projects',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: {
          title: 'Assigned Projects',
          breadcrumb: 'Assigned Projects',
          requiresProjectAccess: true,
          listContext: 'evaluator',
          evaluatorScope: 'assigned',
        },
      },
      {
        path: 'evaluator/completed-projects',
        name: 'evaluator-completed-projects',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: {
          title: 'Completed Evaluations',
          breadcrumb: 'Completed',
          requiresProjectAccess: true,
          listContext: 'evaluator',
          evaluatorScope: 'completed',
        },
      },
      {
        path: 'projects/:id',
        name: 'project-detail',
        component: () => import('@/views/projects/ProjectDetail.vue'),
        meta: { title: 'Project Detail', breadcrumb: 'Project', requiresProjectAccess: true, showBack: true },
      },
      {
        path: 'profile',
        name: 'profile',
        component: () => import('@/views/profile/Profile.vue'),
        meta: { title: 'Profile', breadcrumb: 'Profile' },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: () => import('@/views/admin/UserList.vue'),
        meta: { title: 'Users', breadcrumb: 'Users', requiresAdmin: true },
      },
      {
        path: 'admin/students',
        name: 'admin-students',
        component: () => import('@/views/admin/StudentList.vue'),
        meta: { title: 'Students', breadcrumb: 'Students', requiresAdmin: true },
      },
      {
        path: 'admin/supervisors',
        name: 'admin-supervisors',
        component: () => import('@/views/admin/SupervisorList.vue'),
        meta: {
          title: 'Supervisor Overview',
          breadcrumb: 'Supervisor Overview',
          requiresSupervisorOverview: true,
        },
      },
      {
        path: 'admin/supervisors/:supervisorId/projects',
        name: 'admin-supervisor-projects',
        component: () => import('@/views/admin/SupervisorProfile.vue'),
        meta: {
          title: 'Supervisor Profile',
          breadcrumb: 'Supervisor Profile',
          requiresSupervisorOverview: true,
          breadcrumbTrail: [
            { label: 'Supervisor Overview', to: { name: 'admin-supervisors' } },
            { label: 'Supervisor Profile' },
          ],
        },
      },
      {
        path: 'admin/faculty-management',
        component: () => import('@/views/admin/FacultyManagementLayout.vue'),
        meta: { requiresFacultyManagement: true },
        children: [
          {
            path: '',
            redirect: { name: 'admin-faculty-management-supervisors' },
          },
          {
            path: 'supervisors',
            name: 'admin-faculty-management-supervisors',
            component: () => import('@/views/admin/SupervisorUserList.vue'),
            meta: { title: 'Supervisors', breadcrumb: 'Supervisors', requiresAdmin: true },
          },
          {
            path: 'evaluators',
            name: 'admin-faculty-management-evaluators',
            component: () => import('@/views/admin/EvaluatorUserList.vue'),
            meta: {
              title: 'Evaluators',
              breadcrumb: 'Evaluators',
              requiresEvaluatorManagement: true,
            },
          },
          {
            path: 'faculty',
            name: 'admin-faculty-management-faculty',
            component: () => import('@/views/admin/FacultyList.vue'),
            meta: { title: 'Faculty', breadcrumb: 'Faculty', requiresAdmin: true },
          },
        ],
      },
      {
        path: 'admin/supervisor-users',
        redirect: { name: 'admin-faculty-management-supervisors' },
      },
      {
        path: 'admin/evaluator-users',
        redirect: { name: 'admin-faculty-management-evaluators' },
      },
      {
        path: 'admin/evaluators',
        name: 'admin-evaluators',
        component: () => import('@/views/admin/EvaluatorList.vue'),
        meta: { title: 'Evaluator Overview', breadcrumb: 'Evaluator Overview', requiresEvaluatorManagement: true },
      },
      {
        path: 'admin/evaluators/:evaluatorId/projects',
        name: 'admin-evaluator-projects',
        component: () => import('@/views/projects/ProjectList.vue'),
        meta: {
          title: 'Evaluator Projects',
          breadcrumb: 'Evaluator Projects',
          requiresProjectAccess: true,
          requiresEvaluatorManagement: true,
          listContext: 'faculty-evaluator',
          breadcrumbTrail: [
            { label: 'Evaluator Overview', to: { name: 'admin-evaluators' } },
            { label: 'Assigned Projects' },
          ],
        },
      },
      {
        path: 'admin/evaluator-assignment',
        name: 'admin-evaluator-assignment',
        component: () => import('@/views/admin/EvaluatorAssignmentIndex.vue'),
        meta: { title: 'Evaluator Assignment', breadcrumb: 'Evaluator Assignment', requiresEvaluatorManagement: true },
      },
      {
        path: 'admin/roles',
        name: 'admin-roles',
        component: () => import('@/views/admin/RoleList.vue'),
        meta: { title: 'Roles', breadcrumb: 'Roles', requiresCommitteeHead: true },
      },
      {
        path: 'admin/permissions',
        name: 'admin-permissions',
        component: () => import('@/views/admin/PermissionList.vue'),
        meta: { title: 'Permissions', breadcrumb: 'Permissions', requiresCommitteeHead: true },
      },
      {
        path: 'admin/transfer-requests',
        name: 'admin-transfer-requests',
        component: () => import('@/views/admin/StudentTransferRequestsIndex.vue'),
        meta: {
          title: 'Student Transfer Requests',
          breadcrumb: 'Student Transfer Requests',
          requiresStudentTransferAccess: true,
        },
      },
      {
        path: 'admin/supervisor-change-requests',
        name: 'admin-supervisor-change-requests',
        component: () => import('@/views/admin/SupervisorChangeRequestsIndex.vue'),
        meta: {
          title: 'Supervisor Change Requests',
          breadcrumb: 'Supervisor Change Requests',
          requiresSupervisorChangeAccess: true,
        },
      },
      {
        path: 'admin/proposal-settings',
        name: 'admin-proposal-settings',
        component: () => import('@/views/admin/ProposalSettings.vue'),
        meta: { title: 'Proposal Settings', breadcrumb: 'Proposal Settings', requiresProposalSettings: true },
      },
      {
        path: 'admin/phase-templates',
        name: 'admin-phase-templates',
        component: () => import('@/views/admin/PhaseTemplatesIndex.vue'),
        meta: { title: 'Phase Templates', breadcrumb: 'Phase Templates' },
      },
      {
        path: 'guidelines',
        name: 'guidelines',
        component: () => import('@/views/help/GuidelinesIndex.vue'),
        meta: { title: 'Guidelines', breadcrumb: 'Guidelines' },
      },
      {
        path: 'admin/question-bank',
        name: 'admin-question-bank',
        component: () => import('@/views/admin/QuestionBankIndex.vue'),
        meta: { title: 'Question Bank', breadcrumb: 'Question Bank', requiresQuestionBankAccess: true },
      },
      {
        path: 'admin/proposal-sessions',
        name: 'admin-proposal-sessions',
        component: () => import('@/views/admin/ProposalSessionManagement.vue'),
        meta: { title: 'Proposal Sessions', breadcrumb: 'Proposal Sessions', requiresProposalSessionAccess: true },
      },
      {
        path: 'admin/proposal-sessions/:id',
        name: 'admin-proposal-session-detail',
        component: () => import('@/views/admin/ProposalSessionDetail.vue'),
        meta: { title: 'Session Details', breadcrumb: 'Session Details', requiresProposalSessionAccess: true },
      },
      {
        path: 'admin/programs',
        name: 'admin-programs',
        component: () => import('@/views/admin/ProgramManagement.vue'),
        meta: { title: 'Programs', breadcrumb: 'Programs', requiresProgramManagement: true },
      },
      {
        path: 'admin/activity-log',
        name: 'admin-activity-log',
        component: () => import('@/views/admin/ActivityLogIndex.vue'),
        meta: { title: 'Activity Log', breadcrumb: 'Activity Log', requiresActivityLogAccess: true },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(getBaseUrl()),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const { useAuthStore } = await import('@/stores/auth')
  const authStore = useAuthStore()

  if (!authStore.initialized) {
    await authStore.init()
  }

  const home = () => authStore.resolveHomeRoute()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return next({ name: 'login', query: { redirect: to.fullPath } })
  }

  if (to.meta.guest && authStore.isAuthenticated) {
    return next(home())
  }

  if (to.name === 'dashboard' && !authStore.canViewDashboard) {
    return next(home())
  }

  if (to.meta.requiresAdmin && !authStore.isAdmin) {
    return next(home())
  }

  if (to.meta.requiresEvaluatorManagement && !authStore.canManageEvaluators) {
    return next(home())
  }

  if (to.meta.requiresSupervisorOverview && !authStore.canViewSupervisorOverview) {
    return next(home())
  }

  if (to.meta.requiresCommitteeHead && !authStore.isCommitteeHead) {
    return next(home())
  }

  if (to.meta.requiresProposalSettings && !authStore.canManageProposalSettings) {
    return next(home())
  }

  if (to.meta.requiresProposalSessionAccess && !authStore.canManageProposalSessions && !authStore.canGrantProposalSessionExtensions) {
    return next(home())
  }

  if (to.meta.requiresProposalSessions && !authStore.canManageProposalSessions) {
    return next(home())
  }

  if (to.meta.requiresProgramManagement && !authStore.isAdmin && !authStore.isCommitteeHead) {
    return next(home())
  }

  if (to.meta.requiresFacultyManagement && !authStore.isAdmin && !authStore.canManageEvaluators) {
    return next(home())
  }

  if (to.meta.requiresStudent && !authStore.roles.includes('student')) {
    return next(home())
  }

  if (to.meta.requiresProjectQueryAccess && !authStore.canAccessProjectQueries) {
    return next(home())
  }

  if (to.meta.requiresActivityLogAccess && !authStore.canViewActivityLogs) {
    return next(home())
  }

  if (to.meta.requiresSendNotifications && !authStore.canSendNotifications) {
    return next(home())
  }

  if (to.meta.requiresQuestionBankAccess && !authStore.canManageQuestionBank) {
    return next(home())
  }

  if (to.meta.requiresSupervisorChangeAccess && !authStore.canManageSupervisorChangeRequests) {
    return next(home())
  }

  if (to.meta.requiresStudentTransferAccess && !authStore.canDecideStudentTransfer) {
    return next(home())
  }

  if (to.meta.requiresProjectAccess && !authStore.canAccessProjects) {
    return next(home())
  }

  if (to.meta.listContext === 'all' && !authStore.canViewAllProjects) {
    if (authStore.hasRole('evaluator')) {
      return next({ name: 'evaluator-assigned-projects' })
    }
    if (authStore.hasRole('supervisor')) {
      return next({ name: 'supervisor-projects' })
    }
    return next(home())
  }

  next()
})

router.afterEach((to) => {
  document.title = `${to.meta.title || 'Dashboard'} | FYP Admin`
})

export default router
