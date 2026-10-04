<template>
  <div>
    <PageHeader
      :title="pageTitle"
      :subtitle="pageSubtitle"
      :breadcrumb="isWorkbench ? 'Committee Workbench' : 'Projects'"
    >
      <template v-if="showFacultyBackLink" #actions>
        <router-link :to="facultyBackRoute" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i> Back to Overview
        </router-link>
      </template>
      <template v-else-if="authStore.canViewAllProjects && listContext === 'all'" #actions>
        <button type="button" class="btn btn-outline-primary btn-sm" :disabled="loading" @click="exportProjectList">
          <i class="bi bi-download me-1"></i> Export CSV
        </button>
      </template>
    </PageHeader>

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <ul v-if="isWorkbench" class="nav nav-tabs mb-3">
      <li v-for="tab in workbenchTabs" :key="tab.key" class="nav-item">
        <router-link
          class="nav-link"
          :class="{ active: activeTab === tab.key }"
          :to="{ name: 'committee-workbench', query: { tab: tab.key } }"
        >
          {{ tab.label }}
        </router-link>
      </li>
    </ul>

    <div v-if="canApproveAllPending" class="mb-3">
      <button type="button" class="btn btn-success btn-sm" :disabled="approvingAll" @click="approveAllPending">
        <i class="bi bi-check2-all me-1"></i> Approve All ({{ meta.total }})
      </button>
    </div>

    <div v-if="needsActionOptions.length && !isWorkbench" class="d-flex flex-wrap gap-2 mb-3">
      <button
        v-for="option in needsActionOptions"
        :key="option.key"
        type="button"
        class="btn btn-sm"
        :class="filters.needs_action === option.key ? 'btn-primary' : 'btn-outline-primary'"
        @click="toggleNeedsAction(option.key)"
      >
        {{ option.label }}
      </button>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">{{ filters.visibility === 'trashed' ? 'Deleted Projects' : 'Projects' }}</div>
          <div class="summary-card__value">{{ meta.total }}</div>
        </div>
      </div>
      <div v-if="showDeletedProjectsTab && meta.trashed_count > 0" class="col-6 col-md-3">
        <div class="summary-card summary-card--warning">
          <div class="summary-card__label">Soft Deleted</div>
          <div class="summary-card__value">{{ meta.trashed_count }}</div>
        </div>
      </div>
    </div>

    <ul v-if="showDeletedProjectsTab" class="nav nav-tabs mb-3">
      <li class="nav-item">
        <button
          type="button"
          class="nav-link"
          :class="{ active: filters.visibility === 'active' }"
          @click="setVisibility('active')"
        >
          Active Projects
        </button>
      </li>
      <li class="nav-item">
        <button
          type="button"
          class="nav-link"
          :class="{ active: filters.visibility === 'trashed' }"
          @click="setVisibility('trashed')"
        >
          Deleted Projects
          <span v-if="meta.trashed_count" class="badge bg-danger ms-1">{{ meta.trashed_count }}</span>
        </button>
      </li>
    </ul>

    <AppCard title="Filter Projects" class="mb-3">
      <div class="row g-3 align-items-end filter-toolbar">
        <div class="col-md-4">
          <label class="form-label">Search</label>
          <input
            v-model="filters.search"
            type="search"
            class="form-control"
            placeholder="Project title, leader name, SAP ID..."
            @keyup.enter="applyFilters"
          />
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select v-model="filters.status" class="form-select">
            <option value="">All statuses</option>
            <option v-for="(label, key) in filterOptions.statuses" :key="key" :value="key">
              {{ label }}
            </option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Phase</label>
          <select v-model="filters.phase" class="form-select">
            <option value="">All phases</option>
            <option v-for="(label, key) in filterOptions.phases" :key="key" :value="key">
              {{ label }}
            </option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Workflow</label>
          <select v-model="filters.workflow_stage" class="form-select">
            <option value="">All stages</option>
            <option v-for="(label, key) in workflowStageOptions" :key="key" :value="key">
              {{ label }}
            </option>
          </select>
        </div>
        <div v-if="authStore.canViewAllProjects" class="col-md-2">
          <label class="form-label">Proposal Session</label>
          <select v-model="filters.proposal_session_id" class="form-select">
            <option value="">All sessions</option>
            <option v-for="session in proposalSessions" :key="session.id" :value="session.id">
              {{ session.name }} ({{ session.code }})
            </option>
          </select>
        </div>
        <div v-if="authStore.canViewAllProjects && !isFacultySupervisorView" class="col-md-2">
          <label class="form-label">Supervisor</label>
          <select v-model="filters.supervisor_id" class="form-select">
            <option value="">All supervisors</option>
            <option v-for="supervisor in supervisors" :key="supervisor.id" :value="supervisor.id">
              {{ supervisor.name }}
            </option>
          </select>
        </div>
        <div class="col-md-12 col-lg-auto d-flex gap-2">
          <button type="button" class="btn btn-primary btn-sm" :disabled="loading" @click="applyFilters">
            Apply
          </button>
          <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loading" @click="resetFilters">
            Reset
          </button>
        </div>
      </div>
    </AppCard>

    <DataTable title="Project List" :columns="columns" :rows="projects" :mobile-card-view="true">
      <template #cell-student="{ row }">
        <div>
          <div class="name">{{ row.student?.name || '-' }}</div>
          <div class="email">{{ row.student?.email || '' }}</div>
        </div>
      </template>
      <template #cell-supervisor="{ row }">
        <span class="text-muted small">{{ row.supervisor?.name || '—' }}</span>
      </template>
      <template #cell-members="{ row }">
        <span class="text-muted small">
          {{ (row.members || []).map((m) => m.user?.name).filter(Boolean).join(', ') || '—' }}
        </span>
      </template>
      <template #cell-evaluators="{ row }">
        <span class="text-muted small">
          {{ evaluatorLabel(row) }}
        </span>
      </template>
      <template #cell-my_review="{ row }">
        <div>
          <AppBadge :variant="reviewVariant(row.viewer_evaluator_review?.decision)">
            {{ reviewLabel(row.viewer_evaluator_review?.decision) }}
          </AppBadge>
          <div v-if="row.viewer_evaluator_review?.reviewed_at" class="text-muted small mt-1">
            {{ row.viewer_evaluator_review.reviewed_at }}
          </div>
        </div>
      </template>
      <template #cell-workflow_stage="{ row }">
        <WorkflowStatusBadge :stage="row.workflow_stage" :fallback-label="row.workflow_stage_label" />
      </template>
      <template #cell-current_phase="{ row }">
        <AppBadge variant="primary">{{ row.current_phase_label }}</AppBadge>
      </template>
      <template #cell-status="{ row }">
        <AppBadge :variant="statusVariant(row.status)">{{ row.status }}</AppBadge>
      </template>
      <template #cell-deleted_at="{ row }">
        <span class="text-muted small">{{ row.deleted_at || '—' }}</span>
      </template>
      <template #actions="{ row }">
        <router-link
          v-if="!row.is_trashed"
          :to="`/projects/${row.id}`"
          class="action-btn"
          title="View"
        >
          <i class="bi bi-eye"></i>
        </router-link>
        <button
          v-if="!row.is_trashed && authStore.canManageProjectTeam"
          type="button"
          class="action-btn danger"
          title="Delete project"
          :disabled="loading"
          @click="confirmDeleteProject(row)"
        >
          <i class="bi bi-trash"></i>
        </button>
        <button
          v-if="row.can_force_delete"
          type="button"
          class="action-btn danger"
          title="Permanently delete"
          :disabled="loading"
          @click="confirmForceDeleteProject(row)"
        >
          <i class="bi bi-trash-fill"></i>
        </button>
      </template>
    </DataTable>

    <EmptyState
      v-if="!loading && !projects.length"
      title="No projects found"
      description="Try adjusting your filters or check back later when new projects are registered."
      icon="bi bi-folder-x"
    />

    <div v-if="meta.last_page > 1" class="d-flex justify-content-between align-items-center mt-3">
      <div class="text-muted small">
        Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} projects
      </div>
      <div class="btn-group btn-group-sm">
        <button
          type="button"
          class="btn btn-outline-secondary"
          :disabled="loading || meta.current_page <= 1"
          @click="goToPage(meta.current_page - 1)"
        >
          Previous
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary"
          :disabled="loading || meta.current_page >= meta.last_page"
          @click="goToPage(meta.current_page + 1)"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import DataTable from '@/components/table/DataTable.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import WorkflowStatusBadge from '@/components/ui/WorkflowStatusBadge.vue'
import {
  fetchProjects,
  fetchSupervisors,
  deleteProject,
  forceDeleteProject,
  exportProjects,
  committeeHeadApproveAll,
} from '@/api/projects'
import { fetchProposalSessionOptions } from '@/api/proposalSessions'
import { formatApiError } from '@/utils/apiErrors'
import { useAuthStore } from '@/stores/auth'
import { confirmDialog } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { useCommitteeWorkbench } from '@/composables/useCommitteeWorkbench'

const route = useRoute()
const authStore = useAuthStore()
const projects = ref([])
const supervisors = ref([])
const proposalSessions = ref([])
const loading = ref(false)
const loadError = ref('')
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
  trashed_count: 0,
})
const filterOptions = reactive({
  phases: {},
  statuses: {},
  workflow_stages: {},
  deliverable_workflow_stages: {},
  needs_action_options: {},
})
const filters = reactive({
  search: '',
  status: '',
  phase: '',
  workflow_stage: '',
  needs_action: '',
  proposal_session_id: '',
  supervisor_id: '',
  evaluator_id: '',
  visibility: 'active',
})

const { isWorkbench, activeTab, workbenchTabs, applyWorkbenchFilters } = useCommitteeWorkbench(route)

const approvingAll = ref(false)

const canApproveAllPending = computed(
  () => isWorkbench.value && activeTab.value === 'head' && authStore.roles.includes('fyp-committee-head') && meta.total > 0
)

const showDeletedProjectsTab = computed(
  () => listContext.value === 'all' && authStore.canManageDeletedProjects
)

const listContext = computed(() => route.meta.listContext || 'all')
const evaluatorScope = computed(() => route.meta.evaluatorScope || null)
const facultyMemberName = computed(() => String(route.query.name || '').trim())
const isFacultySupervisorView = computed(() => listContext.value === 'faculty-supervisor')
const isFacultyEvaluatorView = computed(() => listContext.value === 'faculty-evaluator')
const isSupervisorView = computed(() => listContext.value === 'supervisor')
const showFacultyBackLink = computed(() => isFacultySupervisorView.value || isFacultyEvaluatorView.value)
const facultyBackRoute = computed(() =>
  isFacultySupervisorView.value
    ? { name: 'admin-supervisors' }
    : { name: 'admin-evaluators' }
)
const isCompletedEvaluatorView = computed(
  () => listContext.value === 'evaluator' && evaluatorScope.value === 'completed'
)
const isAssignedEvaluatorView = computed(
  () => listContext.value === 'evaluator' && evaluatorScope.value === 'assigned'
)

const pageTitle = computed(() => {
  if (isWorkbench.value) return 'Committee Workbench'
  if (filters.visibility === 'trashed') return 'Deleted FYP Projects'
  if (isFacultySupervisorView.value) {
    return facultyMemberName.value
      ? `${facultyMemberName.value}'s Supervised Projects`
      : 'Supervised Projects'
  }
  if (isFacultyEvaluatorView.value) {
    return facultyMemberName.value
      ? `${facultyMemberName.value}'s Assigned Projects`
      : 'Evaluator Projects'
  }
  if (isCompletedEvaluatorView.value) return 'Completed Evaluations'
  if (isAssignedEvaluatorView.value) return 'My Assigned Projects'
  if (listContext.value === 'all') return 'FYP Projects'
  if (isSupervisorView.value) return 'My Supervised Projects'
  return 'FYP Projects'
})

const pageSubtitle = computed(() => {
  if (isWorkbench.value) {
    return 'Projects waiting for committee review, final decision, or head approval'
  }
  if (isFacultySupervisorView.value) {
    return 'Projects supervised by this faculty member'
  }
  if (isFacultyEvaluatorView.value) {
    return 'Projects assigned to this evaluator'
  }
  if (isCompletedEvaluatorView.value) {
    return 'Projects you have already evaluated'
  }
  if (isAssignedEvaluatorView.value) {
    return 'Projects assigned to you that still need your evaluation'
  }
  if (listContext.value === 'all') {
    if (filters.visibility === 'trashed') {
      return 'Review and permanently remove old soft-deleted project records'
    }
    return 'Browse and filter all student final year projects'
  }
  if (isSupervisorView.value) {
    return 'Projects assigned to you as supervisor'
  }
  return 'Review and track student final year projects'
})

const workflowStageOptions = computed(() => {
  if (filters.phase === 'phase_1' || filters.phase === 'phase_2') {
    return filterOptions.deliverable_workflow_stages
  }

  return filterOptions.workflow_stages
})

const needsActionOptions = computed(() =>
  Object.entries(filterOptions.needs_action_options || {}).map(([key, label]) => ({ key, label }))
)

const toggleNeedsAction = (key) => {
  filters.needs_action = filters.needs_action === key ? '' : key
  if (filters.needs_action) {
    filters.workflow_stage = ''
  }
  meta.current_page = 1
  loadProjects()
}

const columns = computed(() => {
  const cols = [
    { key: 'title', label: 'Project' },
    { key: 'student', label: 'Leader' },
  ]

  if (authStore.canViewAllProjects && !isFacultySupervisorView.value) {
    cols.push({ key: 'supervisor', label: 'Supervisor' })
  }

  cols.push(
    { key: 'members', label: 'Group' },
    { key: 'evaluators', label: 'Evaluators' },
  )

  if (isCompletedEvaluatorView.value) {
    cols.push({ key: 'my_review', label: 'My Review' })
  }

  cols.push(
    { key: 'workflow_stage', label: 'Workflow' },
    { key: 'current_phase', label: 'Phase' },
    { key: 'status', label: 'Status' },
  )

  if (filters.visibility === 'trashed') {
    cols.push({ key: 'deleted_at', label: 'Deleted At' })
  }

  if (!authStore.canManageEvaluators) {
    return cols.filter((col) => col.key !== 'evaluators')
  }

  return cols
})

const evaluatorLabel = (row) => {
  if (authStore.canManageEvaluators) {
    return (row.evaluators || []).map((e) => e.evaluator?.name).filter(Boolean).join(', ') || '—'
  }

  const count = row.evaluator_count ?? (row.evaluators || []).length
  if (!count) return '—'
  return `${count} assigned`
}

const statusVariant = (status) => ({
  active: 'success',
  completed: 'primary',
  suspended: 'danger',
}[status] || 'secondary')

const reviewLabel = (decision) => ({
  accepted: 'Accepted',
  rejected: 'Rejected',
  revision_required: 'Revision Required',
}[decision] || '—')

const reviewVariant = (decision) => ({
  accepted: 'success',
  rejected: 'danger',
  revision_required: 'warning',
}[decision] || 'secondary')

const buildParams = () => {
  const params = {
    page: meta.current_page,
    per_page: meta.per_page,
  }

  if (filters.search) params.search = filters.search
  if (filters.status) params.status = filters.status
  if (filters.phase) params.phase = filters.phase
  if (filters.workflow_stage) params.workflow_stage = filters.workflow_stage
  if (filters.needs_action) params.needs_action = filters.needs_action
  if (filters.proposal_session_id) params.proposal_session_id = filters.proposal_session_id
  if (filters.supervisor_id) params.supervisor_id = filters.supervisor_id
  if (filters.evaluator_id) params.evaluator_id = filters.evaluator_id
  if (showDeletedProjectsTab.value && filters.visibility === 'trashed') {
    params.visibility = 'trashed'
  }

  params.list_context = isFacultySupervisorView.value || isFacultyEvaluatorView.value ? 'all' : listContext.value
  if (listContext.value === 'evaluator' && evaluatorScope.value) {
    params.evaluator_scope = evaluatorScope.value
  }

  return params
}

const loadProjects = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchProjects(buildParams())
    projects.value = res.data.projects || []
    Object.assign(meta, res.data.meta || {})
    if (res.data.filters) {
      Object.assign(filterOptions, res.data.filters)
    }
  } catch (err) {
    projects.value = []
    loadError.value = formatApiError(err, 'Failed to load projects.')
  } finally {
    loading.value = false
  }
}

const approveAllPending = async () => {
  const confirmed = await confirmDialog.confirm({
    title: 'Approve All Pending',
    message: `Approve all ${meta.total} project(s) awaiting your final decision? Each will move to its next stage exactly as if approved individually.`,
    confirmText: 'Approve All',
  })

  if (!confirmed) return

  approvingAll.value = true
  try {
    const res = await committeeHeadApproveAll()
    const { approved_count: approvedCount, failed } = res.data
    if (failed?.length) {
      toast.warning(`${approvedCount} approved, ${failed.length} could not be approved.`)
    } else {
      toast.success(`${approvedCount} project(s) approved.`)
    }
    await loadProjects()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to approve pending projects.'))
  } finally {
    approvingAll.value = false
  }
}

const loadSupervisors = async () => {
  if (!authStore.canViewAllProjects) return
  const res = await fetchSupervisors()
  supervisors.value = res.data
}

const applyFilters = () => {
  meta.current_page = 1
  loadProjects()
}

const syncFacultyFiltersFromRoute = () => {
  if (isFacultySupervisorView.value) {
    filters.supervisor_id = route.params.supervisorId ? String(route.params.supervisorId) : ''
    filters.evaluator_id = ''
    return
  }

  if (isFacultyEvaluatorView.value) {
    filters.evaluator_id = route.params.evaluatorId ? String(route.params.evaluatorId) : ''
    filters.supervisor_id = ''
  }
}

const resetFilters = () => {
  filters.search = ''
  filters.status = ''
  filters.phase = ''
  filters.workflow_stage = ''
  filters.needs_action = ''
  filters.proposal_session_id = ''
  filters.visibility = 'active'
  syncFacultyFiltersFromRoute()
  applyRouteQuery()
  meta.current_page = 1
  loadProjects()
}

const applyRouteQuery = () => {
  if (!isWorkbench.value) {
    if (route.query.phase) filters.phase = String(route.query.phase)
    if (route.query.workflow_stage) filters.workflow_stage = String(route.query.workflow_stage)
    if (route.query.needs_action) filters.needs_action = String(route.query.needs_action)
    if (route.query.proposal_session_id) filters.proposal_session_id = String(route.query.proposal_session_id)
    return
  }

  applyWorkbenchFilters(filters)
}

watch(
  () => [route.query.tab, route.query.phase, route.query.workflow_stage, route.query.needs_action],
  () => {
    applyRouteQuery()
    meta.current_page = 1
    loadProjects()
  }
)

const setVisibility = (visibility) => {
  filters.visibility = visibility
  meta.current_page = 1
  loadProjects()
}

const goToPage = (page) => {
  meta.current_page = page
  loadProjects()
}

const confirmDeleteProject = async (row) => {
  const comments = await confirmDialog.confirm({
    title: 'Delete Project',
    message: `Delete "${row.title}" and dissolve the entire team? This cannot be undone.`,
    confirmText: 'Delete Project',
    variant: 'danger',
    prompt: true,
    promptLabel: 'Optional reason for deletion',
  })

  if (!comments) return

  loading.value = true
  try {
    await deleteProject(row.id, { comments: comments === true ? null : comments })
    toast.success('Project deleted.')
    await loadProjects()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to delete project.'))
  } finally {
    loading.value = false
  }
}

const confirmForceDeleteProject = async (row) => {
  const confirmed = await confirmDialog.confirm({
    title: 'Permanently Delete Project',
    message: `Permanently delete "${row.title}"? This removes the old soft-deleted record and cannot be undone.`,
    confirmText: 'Permanently Delete',
    variant: 'danger',
  })

  if (!confirmed) return

  loading.value = true
  try {
    await forceDeleteProject(row.id)
    toast.success('Project permanently deleted.')
    await loadProjects()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to permanently delete project.'))
  } finally {
    loading.value = false
  }
}

const exportProjectList = async () => {
  loading.value = true
  try {
    await exportProjects(buildParams())
    toast.success('Project export downloaded.')
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to export projects.'))
  } finally {
    loading.value = false
  }
}

const loadProposalSessions = async () => {
  if (!authStore.canViewAllProjects) return
  try {
    const res = await fetchProposalSessionOptions({ include_all: 1 })
    proposalSessions.value = res.data?.sessions || []
  } catch {
    proposalSessions.value = []
  }
}

onMounted(async () => {
  syncFacultyFiltersFromRoute()
  applyRouteQuery()
  await Promise.all([loadProjects(), loadSupervisors(), loadProposalSessions()])
})

watch(
  () => [route.name, route.params.supervisorId, route.params.evaluatorId],
  () => {
    filters.visibility = 'active'
    meta.current_page = 1
    syncFacultyFiltersFromRoute()
    loadProjects()
  }
)
</script>

<style scoped>
.summary-card--warning {
  border-left: 3px solid #ff9f43;
}
</style>
