<template>
  <div class="project-query-panel">
    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-if="loading" class="text-center py-3">
      <div class="spinner-border spinner-border-sm text-primary"></div>
    </div>

    <template v-else>
      <div v-if="!showComposer && !selectedQuery" class="d-flex justify-content-between align-items-center mb-3">
        <div class="text-muted small">{{ queries.length }} quer{{ queries.length === 1 ? 'y' : 'ies' }} for this project</div>
        <button v-if="canRaiseHere" type="button" class="btn btn-primary btn-sm" @click="showComposer = true">
          <i class="bi bi-plus-lg me-1"></i> Raise a Query
        </button>
      </div>

      <div v-if="showComposer" class="workflow-card mb-3">
        <div class="workflow-card__header">Raise a Query</div>
        <div class="workflow-card__body">
          <div v-if="isStaffRaiser" class="alert alert-info py-2 mb-3 small">
            This query will be visible to the project's student team and can be answered by any of them.
          </div>
          <ProjectQueryComposer
            :mode="composerMode"
            :projects="composerProjects"
            :submitting="submitting"
            :error="composerError"
            show-cancel
            @submit="onCreateQuery"
            @cancel="showComposer = false"
          />
        </div>
      </div>

      <div v-else-if="selectedQuery">
        <button type="button" class="btn btn-link btn-sm px-0 mb-2" @click="selectedQuery = null">
          <i class="bi bi-arrow-left me-1"></i> Back to list
        </button>
        <ProjectQueryThread :query="selectedQuery" @updated="onQueryUpdated" />
      </div>

      <div v-else-if="!queries.length" class="text-muted small">No queries have been raised for this project yet.</div>

      <div v-else class="query-list">
        <button
          v-for="item in queries"
          :key="item.id"
          type="button"
          class="query-list-item"
          @click="openQuery(item)"
        >
          <div class="d-flex justify-content-between gap-2">
            <strong>{{ item.subject }}</strong>
            <AppBadge :variant="statusVariant(item.status)">{{ item.status_label }}</AppBadge>
          </div>
          <div class="text-muted small mt-1">
            Raised by {{ item.raiser?.name || 'Unknown' }} · {{ item.created_at }}
          </div>
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import ProjectQueryComposer from '@/components/queries/ProjectQueryComposer.vue'
import ProjectQueryThread from '@/components/queries/ProjectQueryThread.vue'
import { fetchProjectQueries, fetchProjectQuery, createProjectQuery } from '@/api/projectQueries'
import { useAuthStore } from '@/stores/auth'
import { formatApiError } from '@/utils/apiErrors'

const props = defineProps({
  projectId: { type: [Number, String], required: true },
  projectTitle: { type: String, default: '' },
})

const authStore = useAuthStore()

const loading = ref(false)
const loadError = ref('')
const queries = ref([])
const showComposer = ref(false)
const selectedQuery = ref(null)
const submitting = ref(false)
const composerError = ref('')

const canRaiseHere = computed(() =>
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

const composerProjects = computed(() => [{ id: props.projectId, title: props.projectTitle }])

const statusVariant = (status) => ({
  open: 'warning',
  answered: 'info',
  closed: 'secondary',
}[status] || 'secondary')

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchProjectQueries({ project_id: props.projectId, per_page: 50 })
    queries.value = res.data?.queries || []
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load queries.')
  } finally {
    loading.value = false
  }
}

const openQuery = async (item) => {
  try {
    const res = await fetchProjectQuery(item.id)
    selectedQuery.value = res.data
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load query.')
  }
}

const onCreateQuery = async (payload) => {
  submitting.value = true
  composerError.value = ''
  try {
    const res = await createProjectQuery(payload)
    showComposer.value = false
    queries.value = [res.data, ...queries.value]
    selectedQuery.value = res.data
  } catch (err) {
    composerError.value = formatApiError(err, 'Failed to submit query.')
  } finally {
    submitting.value = false
  }
}

const onQueryUpdated = (updated) => {
  selectedQuery.value = updated
  queries.value = queries.value.map((item) => (item.id === updated.id ? updated : item))
}

onMounted(load)
</script>

<style scoped>
.query-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.query-list-item {
  width: 100%;
  text-align: left;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  background: #fff;
  padding: 0.65rem 0.85rem;
}
</style>
