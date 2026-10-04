<template>
  <div>
    <PageHeader
      title="Evaluators"
      subtitle="Evaluation workload by project phase (pending and completed reviews)"
      breadcrumb="Evaluators"
    />

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Evaluators</div>
          <div class="summary-card__value">{{ evaluators.length }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Pending Reviews</div>
          <div class="summary-card__value text-warning">{{ totals.pending }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Completed Reviews</div>
          <div class="summary-card__value text-success">{{ totals.complete }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Total Assignments</div>
          <div class="summary-card__value">{{ totals.assignments }}</div>
        </div>
      </div>
    </div>

    <AppCard title="Filter Evaluators" class="mb-3">
      <div class="row g-3 align-items-end filter-toolbar">
        <div class="col-md-6">
          <label class="form-label">Search</label>
          <input
            v-model="filters.search"
            type="search"
            class="form-control"
            placeholder="Name, email, phone..."
            @keyup.enter="loadEvaluators"
          />
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select v-model="filters.status" class="form-select">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Program</label>
          <select v-model="filters.program_id" class="form-select">
            <option value="">All programs</option>
            <option v-for="program in programFilterOptions" :key="program.id" :value="program.id">
              {{ program.name }}
            </option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Quick Filter</label>
          <select v-model="filters.quick" class="form-select">
            <option value="">All evaluators</option>
            <option value="has_pending">Has pending reviews</option>
            <option value="has_assignments">Has assignments</option>
            <option value="inactive">Inactive or suspended</option>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button type="button" class="btn btn-primary btn-sm" @click="loadEvaluators">Apply</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilters">Reset</button>
        </div>
      </div>
    </AppCard>

    <DataTable title="All Evaluators" :columns="columns" :rows="evaluators" :mobile-card-view="true">
      <template #cell-name="{ row }">
        <div class="avatar-cell">
          <img :src="avatarUrl(row.name)" :alt="row.name" />
          <div>
            <router-link
              v-if="canViewProjects"
              :to="evaluatorProjectsRoute(row)"
              class="name text-decoration-none"
            >
              {{ row.name }}
            </router-link>
            <div v-else class="name">{{ row.name }}</div>
            <div class="email">{{ row.email }}</div>
          </div>
        </div>
      </template>
      <template #cell-program="{ row }">
        <span class="text-muted small">{{ row.program_name || '—' }}</span>
      </template>
      <template #cell-proposal="{ row }">
        <div class="phase-count-cell">
          <AppBadge variant="warning" class="me-1 mb-1">P: {{ row.proposal.pending }}</AppBadge>
          <AppBadge variant="success" class="mb-1">C: {{ row.proposal.complete }}</AppBadge>
        </div>
      </template>
      <template #cell-phase_1="{ row }">
        <div class="phase-count-cell">
          <AppBadge variant="warning" class="me-1 mb-1">P: {{ row.phase_1.pending }}</AppBadge>
          <AppBadge variant="success" class="mb-1">C: {{ row.phase_1.complete }}</AppBadge>
        </div>
      </template>
      <template #cell-phase_2="{ row }">
        <div class="phase-count-cell">
          <AppBadge variant="warning" class="me-1 mb-1">P: {{ row.phase_2.pending }}</AppBadge>
          <AppBadge variant="success" class="mb-1">C: {{ row.phase_2.complete }}</AppBadge>
        </div>
      </template>
      <template #cell-total_pending="{ row }">
        <AppBadge variant="warning">{{ row.total_pending }}</AppBadge>
      </template>
      <template #cell-total_complete="{ row }">
        <AppBadge variant="success">{{ row.total_complete }}</AppBadge>
      </template>
      <template #cell-capacity="{ row }">
        <span class="text-muted small">{{ capacitySummary(row) }}</span>
      </template>
      <template #cell-status="{ row }">
        <AppBadge :variant="statusVariant(row.status)">{{ row.status }}</AppBadge>
      </template>
      <template #cell-actions="{ row }">
        <router-link
          v-if="canViewProjects"
          :to="evaluatorProjectsRoute(row)"
          class="action-btn"
          title="View Projects"
        >
          <i class="bi bi-folder2-open"></i>
        </router-link>
        <button
          v-if="canManageLimits"
          type="button"
          class="action-btn"
          title="Edit Limits"
          @click="openLimitEditor(row)"
        >
          <i class="bi bi-sliders"></i>
        </button>
      </template>
    </DataTable>

    <EmptyState
      v-if="!evaluators.length"
      title="No evaluators found"
      description="Try changing your filters or add evaluators from Faculty Management."
      icon="bi bi-person-check"
    />

    <div v-if="limitEditor.open" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.35);">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Evaluation Limits — {{ limitEditor.evaluator?.name }}</h5>
            <button type="button" class="btn-close" @click="closeLimitEditor"></button>
          </div>
          <form @submit.prevent="saveLimits">
            <div class="modal-body">
              <p class="text-muted small">
                Maximum number of projects this evaluator can be assigned per phase. Leave blank to use the
                default limits from Proposal Settings.
              </p>
              <div v-if="limitEditor.error" class="alert alert-danger py-2">{{ limitEditor.error }}</div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Proposal</label>
                  <input
                    v-model="limitEditor.form.proposal"
                    type="number"
                    min="0"
                    max="100"
                    class="form-control"
                    placeholder="Default"
                  />
                  <div class="form-text">Current: {{ limitEditor.evaluator?.proposal.total }}/{{ limitEditor.evaluator?.effective_limit_proposal }}</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Phase-1</label>
                  <input
                    v-model="limitEditor.form.phase_1"
                    type="number"
                    min="0"
                    max="100"
                    class="form-control"
                    placeholder="Default"
                  />
                  <div class="form-text">Current: {{ limitEditor.evaluator?.phase_1.total }}/{{ limitEditor.evaluator?.effective_limit_phase_1 }}</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Phase-2</label>
                  <input
                    v-model="limitEditor.form.phase_2"
                    type="number"
                    min="0"
                    max="100"
                    class="form-control"
                    placeholder="Default"
                  />
                  <div class="form-text">Current: {{ limitEditor.evaluator?.phase_2.total }}/{{ limitEditor.evaluator?.effective_limit_phase_2 }}</div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary btn-sm" @click="closeLimitEditor">Cancel</button>
              <button type="submit" class="btn btn-primary btn-sm" :disabled="limitEditor.saving">Save Limits</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import DataTable from '@/components/table/DataTable.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { fetchEvaluatorOverview, updateEvaluatorLimits } from '@/api/evaluatorOverview'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/apiErrors'
import { toast } from '@/composables/useToast'

const authStore = useAuthStore()
const evaluators = ref([])
const canViewProjects = computed(() => authStore.canViewAllProjects)
const canManageLimits = computed(() => authStore.canManageProposalSettings)

const limitEditor = reactive({
  open: false,
  saving: false,
  error: '',
  evaluator: null,
  form: {
    proposal: '',
    phase_1: '',
    phase_2: '',
  },
})

const filters = reactive({
  search: '',
  status: '',
  program_id: '',
  quick: '',
})

const programFilterOptions = computed(() => authStore.accessiblePrograms || [])

const columns = computed(() => {
  const base = [
    { key: 'name', label: 'Evaluator' },
    { key: 'program', label: 'Program' },
    { key: 'proposal', label: 'Proposal' },
    { key: 'phase_1', label: 'Phase-1' },
    { key: 'phase_2', label: 'Phase-2' },
    { key: 'total_pending', label: 'Pending' },
    { key: 'total_complete', label: 'Complete' },
    { key: 'capacity', label: 'Capacity' },
    { key: 'status', label: 'Status' },
  ]

  if (canViewProjects.value || canManageLimits.value) {
    base.push({ key: 'actions', label: 'Actions' })
  }

  return base
})

const evaluatorProjectsRoute = (row) => ({
  name: 'admin-evaluator-projects',
  params: { evaluatorId: row.id },
  query: { name: row.name },
})

const totals = computed(() =>
  evaluators.value.reduce(
    (acc, row) => ({
      pending: acc.pending + row.total_pending,
      complete: acc.complete + row.total_complete,
      assignments: acc.assignments + row.total_assignments,
    }),
    { pending: 0, complete: 0, assignments: 0 }
  )
)

const avatarUrl = (name) =>
  `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=28c76f&color=fff`

const capacitySummary = (row) => {
  const parts = [
    `P ${row.proposal.total}/${row.effective_limit_proposal ?? '—'}`,
    `1 ${row.phase_1.total}/${row.effective_limit_phase_1 ?? '—'}`,
    `2 ${row.phase_2.total}/${row.effective_limit_phase_2 ?? '—'}`,
  ]

  return parts.join(' · ')
}

const openLimitEditor = (row) => {
  limitEditor.evaluator = row
  limitEditor.form.proposal = row.evaluation_limit_proposal ?? ''
  limitEditor.form.phase_1 = row.evaluation_limit_phase_1 ?? ''
  limitEditor.form.phase_2 = row.evaluation_limit_phase_2 ?? ''
  limitEditor.error = ''
  limitEditor.open = true
}

const closeLimitEditor = () => {
  limitEditor.open = false
  limitEditor.evaluator = null
  limitEditor.error = ''
}

const parseLimit = (value) => {
  if (value === '' || value === null || value === undefined) {
    return null
  }

  return Number(value)
}

const saveLimits = async () => {
  if (!limitEditor.evaluator) return

  limitEditor.saving = true
  limitEditor.error = ''

  try {
    const res = await updateEvaluatorLimits(limitEditor.evaluator.id, {
      evaluation_limit_proposal: parseLimit(limitEditor.form.proposal),
      evaluation_limit_phase_1: parseLimit(limitEditor.form.phase_1),
      evaluation_limit_phase_2: parseLimit(limitEditor.form.phase_2),
    })

    const index = evaluators.value.findIndex((row) => row.id === limitEditor.evaluator.id)
    if (index >= 0) {
      evaluators.value[index] = res.data
    }

    closeLimitEditor()
    toast.success('Evaluation limits updated.')
  } catch (error) {
    limitEditor.error = formatApiError(error)
  } finally {
    limitEditor.saving = false
  }
}

const statusVariant = (status) => ({
  active: 'success',
  inactive: 'secondary',
  suspended: 'danger',
}[status] || 'secondary')

const buildParams = () => {
  const params = {}
  if (filters.search.trim()) params.search = filters.search.trim()
  if (filters.status) params.status = filters.status
  if (filters.program_id) params.program_id = filters.program_id
  return params
}

const loadEvaluators = async () => {
  const res = await fetchEvaluatorOverview(buildParams())
  let rows = res.data || []

  if (filters.quick === 'has_pending') {
    rows = rows.filter((row) => row.total_pending > 0)
  } else if (filters.quick === 'has_assignments') {
    rows = rows.filter((row) => row.total_assignments > 0)
  } else if (filters.quick === 'inactive') {
    rows = rows.filter((row) => row.status !== 'active')
  }

  evaluators.value = rows
}

const resetFilters = () => {
  filters.search = ''
  filters.status = ''
  filters.program_id = ''
  filters.quick = ''
  loadEvaluators()
}

onMounted(loadEvaluators)
</script>

<style scoped>
.summary-card {
  background: #fff;
  border: 1px solid #ebe9f1;
  border-radius: 0.428rem;
  padding: 1rem 1.15rem;
}

.summary-card__label {
  color: #6e6b7b;
  font-size: 0.82rem;
  margin-bottom: 0.25rem;
}

.summary-card__value {
  color: #5e5873;
  font-size: 1.35rem;
  font-weight: 700;
}

.phase-count-cell {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}
</style>
