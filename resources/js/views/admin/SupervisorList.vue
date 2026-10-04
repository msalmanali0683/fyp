<template>
  <div>
    <PageHeader
      title="Supervisors"
      subtitle="Supervision workload by project phase"
      breadcrumb="Supervisors"
    />

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Supervisors</div>
          <div class="summary-card__value">{{ supervisors.length }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Proposal Groups</div>
          <div class="summary-card__value">{{ totals.proposal }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Phase-1 Groups</div>
          <div class="summary-card__value">{{ totals.phase1 }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Phase-2 Groups</div>
          <div class="summary-card__value">{{ totals.phase2 }}</div>
        </div>
      </div>
    </div>

    <AppCard title="Filter Supervisors" class="mb-3">
      <div class="row g-3 align-items-end filter-toolbar">
        <div class="col-md-6">
          <label class="form-label">Search</label>
          <input
            v-model="filters.search"
            type="search"
            class="form-control"
            placeholder="Name, email, phone..."
            @keyup.enter="loadSupervisors"
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
            <option value="">All supervisors</option>
            <option value="has_projects">Has supervised projects</option>
            <option value="at_capacity">At/near capacity</option>
            <option value="inactive">Inactive or suspended</option>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button type="button" class="btn btn-primary btn-sm" @click="loadSupervisors">Apply</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilters">Reset</button>
        </div>
      </div>
    </AppCard>

    <DataTable title="All Supervisors" :columns="columns" :rows="supervisors" :mobile-card-view="true">
      <template #cell-name="{ row }">
        <div class="avatar-cell">
          <img :src="avatarUrl(row.name)" :alt="row.name" />
          <div>
            <router-link
              v-if="canViewProjects"
              :to="supervisorProjectsRoute(row)"
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
      <template #cell-proposal_groups="{ row }">
        <AppBadge variant="primary">{{ row.proposal_groups }}</AppBadge>
      </template>
      <template #cell-phase_1_groups="{ row }">
        <AppBadge variant="info">{{ row.phase_1_groups }}</AppBadge>
      </template>
      <template #cell-phase_2_groups="{ row }">
        <AppBadge variant="success">{{ row.phase_2_groups }}</AppBadge>
      </template>
      <template #cell-total_groups="{ row }">
        <strong>{{ row.total_groups }}</strong>
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
          :to="supervisorProjectsRoute(row)"
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
      v-if="!supervisors.length"
      title="No supervisors found"
      description="Try changing your filters or add supervisors from Faculty Management."
      icon="bi bi-person-badge"
    />

    <div v-if="limitEditor.open" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.35);">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Supervision Limits — {{ limitEditor.supervisor?.name }}</h5>
            <button type="button" class="btn-close" @click="closeLimitEditor"></button>
          </div>
          <form @submit.prevent="saveLimits">
            <div class="modal-body">
              <p class="text-muted small">
                Leave blank to use the default limits from Proposal Settings.
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
                  <div class="form-text">Current: {{ limitEditor.supervisor?.proposal_groups }}/{{ limitEditor.supervisor?.effective_limit_proposal }}</div>
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
                  <div class="form-text">Current: {{ limitEditor.supervisor?.phase_1_groups }}/{{ limitEditor.supervisor?.effective_limit_phase_1 }}</div>
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
                  <div class="form-text">Current: {{ limitEditor.supervisor?.phase_2_groups }}/{{ limitEditor.supervisor?.effective_limit_phase_2 }}</div>
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
import { fetchSupervisorOverview } from '@/api/supervisors'
import { updateSupervisorLimits } from '@/api/proposals'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/apiErrors'
import { toast } from '@/composables/useToast'

const authStore = useAuthStore()
const supervisors = ref([])
const canManageLimits = computed(() => authStore.canManageProposalSettings)
const canViewProjects = computed(() => authStore.canViewAllProjects)

const limitEditor = reactive({
  open: false,
  saving: false,
  error: '',
  supervisor: null,
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
    { key: 'name', label: 'Supervisor' },
    { key: 'program', label: 'Program' },
    { key: 'proposal_groups', label: 'Proposal' },
    { key: 'phase_1_groups', label: 'Phase-1' },
    { key: 'phase_2_groups', label: 'Phase-2' },
    { key: 'total_groups', label: 'Total Groups' },
    { key: 'capacity', label: 'Capacity' },
    { key: 'status', label: 'Status' },
  ]

  if (canViewProjects.value || canManageLimits.value) {
    base.push({ key: 'actions', label: 'Actions' })
  }

  return base
})

const supervisorProjectsRoute = (row) => ({
  name: 'admin-supervisor-projects',
  params: { supervisorId: row.id },
  query: { name: row.name },
})

const totals = computed(() =>
  supervisors.value.reduce(
    (acc, row) => ({
      proposal: acc.proposal + row.proposal_groups,
      phase1: acc.phase1 + row.phase_1_groups,
      phase2: acc.phase2 + row.phase_2_groups,
    }),
    { proposal: 0, phase1: 0, phase2: 0 }
  )
)

const avatarUrl = (name) =>
  `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=7367f0&color=fff`

const capacitySummary = (row) => {
  const parts = [
    `P ${row.proposal_groups}/${row.effective_limit_proposal ?? '—'}`,
    `1 ${row.phase_1_groups}/${row.effective_limit_phase_1 ?? '—'}`,
    `2 ${row.phase_2_groups}/${row.effective_limit_phase_2 ?? '—'}`,
  ]

  return parts.join(' · ')
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

const loadSupervisors = async () => {
  const res = await fetchSupervisorOverview(buildParams())
  let rows = res.data || []

  if (filters.quick === 'has_projects') {
    rows = rows.filter((row) => row.total_groups > 0)
  } else if (filters.quick === 'at_capacity') {
    rows = rows.filter((row) =>
      row.total_groups >= (row.effective_limit_proposal || 999)
      || row.proposal_groups >= (row.effective_limit_proposal || 999)
    )
  } else if (filters.quick === 'inactive') {
    rows = rows.filter((row) => row.status !== 'active')
  }

  supervisors.value = rows
}

const resetFilters = () => {
  filters.search = ''
  filters.status = ''
  filters.program_id = ''
  filters.quick = ''
  loadSupervisors()
}

const openLimitEditor = (row) => {
  limitEditor.supervisor = row
  limitEditor.form.proposal = row.supervision_limit_proposal ?? ''
  limitEditor.form.phase_1 = row.supervision_limit_phase_1 ?? ''
  limitEditor.form.phase_2 = row.supervision_limit_phase_2 ?? ''
  limitEditor.error = ''
  limitEditor.open = true
}

const closeLimitEditor = () => {
  limitEditor.open = false
  limitEditor.supervisor = null
  limitEditor.error = ''
}

const parseLimit = (value) => {
  if (value === '' || value === null || value === undefined) {
    return null
  }

  return Number(value)
}

const saveLimits = async () => {
  if (!limitEditor.supervisor) return

  limitEditor.saving = true
  limitEditor.error = ''

  try {
    const res = await updateSupervisorLimits(limitEditor.supervisor.id, {
      supervision_limit_proposal: parseLimit(limitEditor.form.proposal),
      supervision_limit_phase_1: parseLimit(limitEditor.form.phase_1),
      supervision_limit_phase_2: parseLimit(limitEditor.form.phase_2),
    })

    const index = supervisors.value.findIndex((row) => row.id === limitEditor.supervisor.id)
    if (index >= 0) {
      supervisors.value[index] = res.data
    }

    closeLimitEditor()
    toast.success('Supervision limits updated.')
  } catch (error) {
    limitEditor.error = formatApiError(error)
  } finally {
    limitEditor.saving = false
  }
}

onMounted(loadSupervisors)
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
</style>
