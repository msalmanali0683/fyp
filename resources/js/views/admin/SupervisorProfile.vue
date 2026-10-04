<template>
  <div>
    <PageHeader
      :title="supervisor?.name || route.query.name || 'Supervisor Profile'"
      :subtitle="supervisor?.email"
      :breadcrumb-trail="breadcrumbTrail"
    >
      <template #actions>
        <router-link :to="{ name: 'admin-supervisors' }" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i> Back to Supervisors
        </router-link>
      </template>
    </PageHeader>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <div v-else-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-else>
      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="summary-card">
            <div class="summary-card__label">Proposal</div>
            <div class="summary-card__value">{{ supervisor.proposal_groups }} / {{ supervisor.effective_limit_proposal }}</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-card">
            <div class="summary-card__label">Phase-1</div>
            <div class="summary-card__value">{{ supervisor.phase_1_groups }} / {{ supervisor.effective_limit_phase_1 }}</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-card">
            <div class="summary-card__label">Phase-2</div>
            <div class="summary-card__value">{{ supervisor.phase_2_groups }} / {{ supervisor.effective_limit_phase_2 }}</div>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="summary-card">
            <div class="summary-card__label">Total Active</div>
            <div class="summary-card__value">{{ supervisor.total_groups }}</div>
          </div>
        </div>
      </div>

      <AppCard
        title="Pending Tasks"
        subtitle="Projects currently waiting on this supervisor — open one to act on their behalf"
        class="mb-3"
      >
        <EmptyState
          v-if="!pendingTasks.length"
          title="Nothing pending"
          description="This supervisor has no projects currently waiting on their action."
          icon="bi bi-check2-circle"
        />
        <div v-else class="pending-task-list">
          <router-link
            v-for="task in pendingTasks"
            :key="task.id"
            :to="`/projects/${task.id}`"
            class="pending-task-item"
          >
            <div>
              <div class="fw-semibold">{{ task.title }}</div>
              <div class="text-muted small">{{ task.student?.name }}</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <AppBadge variant="info">{{ task.current_phase_label }}</AppBadge>
              <AppBadge variant="warning">{{ task.workflow_stage_label }}</AppBadge>
              <i class="bi bi-chevron-right text-muted"></i>
            </div>
          </router-link>
        </div>
      </AppCard>

      <AppCard title="All Projects" :subtitle="`${meta.total} project(s) supervised`">
        <DataTable :columns="projectColumns" :rows="projects">
          <template #cell-title="{ row }">
            <router-link :to="`/projects/${row.id}`" class="text-decoration-none">{{ row.title }}</router-link>
          </template>
          <template #cell-student="{ row }">
            {{ row.student?.name || '—' }}
          </template>
          <template #cell-current_phase_label="{ row }">
            <AppBadge variant="info">{{ row.current_phase_label }}</AppBadge>
          </template>
          <template #cell-workflow_stage_label="{ row }">
            <AppBadge :variant="row.workflow_stage === 'approved' ? 'success' : 'secondary'">
              {{ row.workflow_stage_label }}
            </AppBadge>
          </template>
        </DataTable>

        <div v-if="meta.last_page > 1" class="d-flex justify-content-between align-items-center mt-3">
          <div class="text-muted small">Page {{ meta.current_page }} of {{ meta.last_page }}</div>
          <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" :disabled="loading || meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">
              Previous
            </button>
            <button type="button" class="btn btn-outline-secondary" :disabled="loading || meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">
              Next
            </button>
          </div>
        </div>
      </AppCard>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DataTable from '@/components/table/DataTable.vue'
import { fetchSupervisorProfile } from '@/api/supervisors'
import { formatApiError } from '@/utils/apiErrors'

const route = useRoute()

const loading = ref(true)
const loadError = ref('')
const supervisor = ref(null)
const pendingTasks = ref([])
const projects = ref([])
const meta = reactive({ current_page: 1, last_page: 1, per_page: 20, total: 0 })

const projectColumns = [
  { key: 'title', label: 'Project' },
  { key: 'student', label: 'Leader' },
  { key: 'current_phase_label', label: 'Phase' },
  { key: 'workflow_stage_label', label: 'Stage' },
]

const breadcrumbTrail = computed(() => [
  { label: 'Supervisor Overview', to: { name: 'admin-supervisors' } },
  { label: supervisor.value?.name || 'Profile' },
])

const load = async (page = 1) => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchSupervisorProfile(route.params.supervisorId, { page, per_page: meta.per_page })
    supervisor.value = res.data?.supervisor || null
    pendingTasks.value = res.data?.pending_tasks || []
    projects.value = res.data?.projects || []
    Object.assign(meta, res.data?.meta || {})
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load supervisor profile.')
  } finally {
    loading.value = false
  }
}

const goToPage = (page) => load(page)

watch(() => route.params.supervisorId, () => load(1))

load(1)
</script>

<style scoped>
.summary-card {
  background: #fff;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  padding: 1rem;
}

.summary-card__label {
  color: #6e6b7b;
  font-size: 0.8125rem;
  text-transform: uppercase;
}

.summary-card__value {
  font-size: 1.5rem;
  font-weight: 700;
}

.pending-task-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.pending-task-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  text-decoration: none;
  color: inherit;
  transition: box-shadow 0.2s ease;
}

.pending-task-item:hover {
  box-shadow: 0 0.35rem 1rem rgba(67, 89, 113, 0.12);
}
</style>
