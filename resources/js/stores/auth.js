import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as loginApi, logout as logoutApi, fetchUser } from '@/api/auth'
import { csrf } from '@/api/axios'
import router from '@/router'
import { USER_MANAGEMENT_ROLES } from '@/utils/roles'

const PROJECT_ACCESS_ROLES = ['supervisor', 'admin', 'evaluator', 'fyp-committee-member', 'fyp-committee-head']

const USER_MANAGEMENT_PERMISSIONS = [
  'view users',
  'create users',
  'edit users',
  'delete users',
  'assign user roles',
  'assign user permissions',
]

const ROLE_MANAGEMENT_PERMISSIONS = [
  'view roles',
  'create roles',
  'edit roles',
  'delete roles',
]

const PROJECT_ACCESS_PERMISSIONS = [
  'view projects',
  'view all projects',
  'view evaluators',
  'view supervisors',
  'assign evaluators',
]

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const accessiblePrograms = ref([])
  const isGlobalAdmin = ref(false)
  const loading = ref(false)
  const initialized = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const roles = computed(() => user.value?.roles || [])
  const permissions = computed(() => user.value?.permissions || [])

  const hasRole = (role) => roles.value.includes(role)
  const hasAnyRole = (roleList) => roleList.some((role) => roles.value.includes(role))
  const hasPermission = (permission) => permissions.value.includes(permission)
  const hasAnyPermission = (permissionList) =>
    permissionList.some((permission) => permissions.value.includes(permission))

  const isAdmin = computed(
    () => hasAnyRole(USER_MANAGEMENT_ROLES) || hasAnyPermission(USER_MANAGEMENT_PERMISSIONS)
  )
  const isCommitteeHead = computed(
    () => hasRole('fyp-committee-head') || hasAnyPermission(ROLE_MANAGEMENT_PERMISSIONS)
  )
  const isStudent = computed(() => hasRole('student'))
  const canAccessProjects = computed(
    () => hasAnyRole(PROJECT_ACCESS_ROLES) || hasAnyPermission(PROJECT_ACCESS_PERMISSIONS)
  )
  const canViewAllProjects = computed(() => {
    if (hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member'])) {
      return true
    }
    if (hasRole('supervisor')) {
      return false
    }
    if (hasRole('evaluator')) {
      return hasPermission('view all projects')
    }
    return hasPermission('view all projects')
  })
  const canManageEvaluators = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member']) ||
      hasPermission('assign evaluators')
  )
  const canViewSupervisorOverview = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member']) ||
      hasPermission('view supervisors')
  )
  const canAssignEvaluators = computed(() => canManageEvaluators.value)
  const canManageProjectTeam = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('manage project team') ||
      hasPermission('remove team members')
  )
  const canManageDeletedProjects = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('delete projects') ||
      hasPermission('manage project team')
  )
  const canManageProposalSettings = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('manage proposal settings')
  )
  const canManageProposalSessions = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('manage proposal sessions')
  )
  const canGrantProposalSessionExtensions = computed(
    () =>
      canManageProposalSessions.value ||
      hasPermission('grant proposal session extensions')
  )
  const canDecideSupervisorChange = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('decide supervisor change')
  )
  const canManageSupervisorInvitations = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('manage supervisor invitations')
  )
  const canManageSupervisorChangeRequests = computed(
    () => canDecideSupervisorChange.value || canManageSupervisorInvitations.value
  )
  const canDecideStudentTransfer = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head']) ||
      hasPermission('decide student transfer')
  )
  const canViewDashboard = computed(() => hasPermission('view dashboard'))
  const canRespondProjectQueries = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member']) ||
      hasPermission('respond to project queries')
  )
  const canAccessProjectQueries = computed(
    () =>
      canRespondProjectQueries.value ||
      hasRole('student') ||
      hasRole('supervisor') ||
      hasRole('evaluator')
  )
  const canViewActivityLogs = computed(
    () => hasAnyRole(['admin', 'fyp-committee-head']) || hasPermission('view activity logs')
  )
  const canSendNotifications = computed(
    () =>
      hasAnyRole(['admin', 'fyp-committee-head', 'fyp-committee-member', 'supervisor']) ||
      hasPermission('send notifications')
  )
  const canDeleteNotifications = computed(
    () => hasAnyRole(['admin', 'fyp-committee-head']) || hasPermission('delete notifications')
  )
  const canManagePhaseTemplates = computed(
    () => hasAnyRole(['admin', 'fyp-committee-head']) || hasPermission('manage phase templates')
  )
  const canManageQuestionBank = computed(
    () => hasAnyRole(['admin', 'fyp-committee-head']) || hasPermission('manage evaluation questions')
  )
  const primaryProgramName = computed(() => {
    if (isGlobalAdmin.value) return 'All Programs'
    return user.value?.program_name || accessiblePrograms.value[0]?.name || ''
  })

  const applyAuthPayload = (payload) => {
    user.value = payload.user
    accessiblePrograms.value = payload.accessible_programs || []
    isGlobalAdmin.value = !!payload.is_global_admin
  }

  const resolveHomeRoute = () => {
    if (canViewDashboard.value) {
      return { name: 'dashboard' }
    }
    if (isStudent.value) {
      return { name: 'my-project' }
    }
    if (hasRole('evaluator')) {
      return { name: 'evaluator-assigned-projects' }
    }
    if (hasRole('supervisor')) {
      return { name: 'supervisor-projects' }
    }
    if (canViewAllProjects.value) {
      return { name: 'projects' }
    }
    return { name: 'profile' }
  }

  const init = async () => {
    try {
      loading.value = true
      await csrf()
      const response = await fetchUser()
      applyAuthPayload(response.data)
    } catch {
      user.value = null
      accessiblePrograms.value = []
      isGlobalAdmin.value = false
    } finally {
      loading.value = false
      initialized.value = true
    }
  }

  const login = async (credentials) => {
    loading.value = true
    try {
      const response = await loginApi(credentials)
      applyAuthPayload(response.data)
      return response
    } finally {
      loading.value = false
    }
  }

  const logout = async () => {
    try {
      await logoutApi()
    } finally {
      user.value = null
      accessiblePrograms.value = []
      isGlobalAdmin.value = false
      router.push({ name: 'login' })
    }
  }

  window.addEventListener('auth:logout', () => {
    user.value = null
    accessiblePrograms.value = []
    isGlobalAdmin.value = false
    if (router.currentRoute.value.meta.requiresAuth) {
      router.push({ name: 'login' })
    }
  })

  return {
    user,
    accessiblePrograms,
    isGlobalAdmin,
    primaryProgramName,
    loading,
    initialized,
    isAuthenticated,
    roles,
    permissions,
    hasRole,
    hasAnyRole,
    hasPermission,
    hasAnyPermission,
    isAdmin,
    isCommitteeHead,
    isStudent,
    canAccessProjects,
    canViewAllProjects,
    canManageEvaluators,
    canViewSupervisorOverview,
    canAssignEvaluators,
    canManageProjectTeam,
    canManageDeletedProjects,
    canManageProposalSettings,
    canManageProposalSessions,
    canGrantProposalSessionExtensions,
    canDecideSupervisorChange,
    canManageSupervisorInvitations,
    canManageSupervisorChangeRequests,
    canDecideStudentTransfer,
    canViewDashboard,
    canRespondProjectQueries,
    canAccessProjectQueries,
    canViewActivityLogs,
    canSendNotifications,
    canDeleteNotifications,
    canManagePhaseTemplates,
    canManageQuestionBank,
    resolveHomeRoute,
    init,
    login,
    logout,
  }
})
