<template>
  <div>
    <PageHeader title="Project Queries" subtitle="Raise and respond to project-related questions" breadcrumb="Queries">
      <template #actions>
        <button v-if="canRaise" type="button" class="btn btn-primary btn-sm" @click="openComposer">
          <i class="bi bi-plus-lg me-1"></i> Raise a Query
        </button>
      </template>
    </PageHeader>

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <AppCard title="Inbox">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <select v-model="statusFilter" class="form-select form-select-sm w-auto" @change="reload">
          <option value="">All statuses</option>
          <option value="open">Open</option>
          <option value="answered">Answered</option>
          <option value="closed">Closed</option>
        </select>
      </div>

      <div v-if="loading" class="text-center py-4">
        <div class="spinner-border spinner-border-sm text-primary"></div>
      </div>

      <EmptyState
        v-else-if="!items.length"
        title="No queries found"
        description="Queries raised for your projects will appear here."
        icon="bi bi-chat-left-text"
      />

      <div v-else class="query-index-list">
        <button
          v-for="item in items"
          :key="item.id"
          type="button"
          class="query-index-item"
          @click="openThread(item)"
        >
          <div class="d-flex justify-content-between gap-2">
            <strong>{{ item.subject }}</strong>
            <AppBadge :variant="statusVariant(item.status)">{{ item.status_label }}</AppBadge>
          </div>
          <div class="text-muted mt-1 small">
            {{ item.project?.title }} · Raised by {{ item.raiser?.name || 'Unknown' }} · {{ item.created_at }}
          </div>
        </button>
      </div>

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

    <div v-if="composerModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header">
            <h5 class="modal-title">Raise a Query</h5>
            <button type="button" class="btn-close" @click="composerModal = false"></button>
          </div>
          <div class="modal-body">
            <div v-if="isStaffRaiser" class="alert alert-info py-2 mb-3 small">
              This query will be visible to the selected project's student team and can be answered by any of them.
            </div>
            <ProjectQueryComposer
              :mode="composerMode"
              :projects="myProjects"
              :submitting="submitting"
              :error="composerError"
              @submit="onCreateQuery"
            />
          </div>
        </div>
      </div>
    </div>

    <div v-if="threadModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
          <div class="modal-header">
            <h5 class="modal-title">Query</h5>
            <button type="button" class="btn-close" @click="threadModal = false"></button>
          </div>
          <div class="modal-body">
            <ProjectQueryThread v-if="activeQuery" :query="activeQuery" @updated="onQueryUpdated" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ProjectQueryComposer from '@/components/queries/ProjectQueryComposer.vue'
import ProjectQueryThread from '@/components/queries/ProjectQueryThread.vue'
import {
  fetchProjectQueries,
  fetchProjectQuery,
  fetchMyQueryableProjects,
  createProjectQuery,
} from '@/api/projectQueries'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/apiErrors'

const authStore = useAuthStore()

const items = ref([])
const loading = ref(false)
const loadError = ref('')
const statusFilter = ref('')
const meta = reactive({ current_page: 1, last_page: 1, total: 0 })

const composerModal = ref(false)
const threadModal = ref(false)
const activeQuery = ref(null)
const myProjects = ref([])
const submitting = ref(false)
const composerError = ref('')

const canRaise = computed(() =>
  authStore.hasRole('student') ||
  authStore.hasRole('supervisor') ||
  authStore.hasRole('evaluator') ||
  authStore.canRespondProjectQueries
)
const composerMode = computed(() => (authStore.hasRole('student') ? 'student' : 'staff'))

const isStaffRaiser = computed(
  () =>
    authStore.canRespondProjectQueries &&
    !authStore.hasRole('student') &&
    !authStore.hasRole('supervisor') &&
    !authStore.hasRole('evaluator')
)

const statusVariant = (status) => ({
  open: 'warning',
  answered: 'info',
  closed: 'secondary',
}[status] || 'secondary')

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchProjectQueries({
      page: meta.current_page,
      per_page: 15,
      status: statusFilter.value || undefined,
    })
    items.value = res.data?.queries || []
    Object.assign(meta, res.data?.meta || {})
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load queries.')
  } finally {
    loading.value = false
  }
}

const reload = () => {
  meta.current_page = 1
  load()
}

const goToPage = (page) => {
  meta.current_page = page
  load()
}

const openThread = async (item) => {
  try {
    const res = await fetchProjectQuery(item.id)
    activeQuery.value = res.data
    threadModal.value = true
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load query.')
  }
}

const onQueryUpdated = (updated) => {
  activeQuery.value = updated
  items.value = items.value.map((item) => (item.id === updated.id ? updated : item))
}

const openComposer = async () => {
  composerError.value = ''

  if (composerMode.value === 'staff' && !myProjects.value.length) {
    try {
      const res = await fetchMyQueryableProjects()
      myProjects.value = res.data || []
    } catch (err) {
      composerError.value = formatApiError(err, 'Failed to load your projects.')
    }
  }

  composerModal.value = true
}

const onCreateQuery = async (payload) => {
  submitting.value = true
  composerError.value = ''
  try {
    const res = await createProjectQuery(payload)
    composerModal.value = false
    items.value = [res.data, ...items.value]
  } catch (err) {
    composerError.value = formatApiError(err, 'Failed to submit query.')
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.query-index-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.query-index-item {
  width: 100%;
  text-align: left;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  background: #fff;
  padding: 0.85rem 1rem;
}
</style>
