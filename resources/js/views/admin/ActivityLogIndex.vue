<template>
  <div>
    <PageHeader
      title="Activity Log"
      subtitle="Every action taken by admins, committee members, and staff across student projects"
      breadcrumb="Activity Log"
    />

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <AppCard title="Log Book">
      <div class="row g-2 mb-3">
        <div class="col-md-4">
          <input
            v-model="filters.search"
            type="search"
            class="form-control form-control-sm"
            placeholder="Search description, action, or user..."
            @input="debouncedReload"
          />
        </div>
        <div class="col-md-3">
          <select v-model="filters.module" class="form-select form-select-sm" @change="reload">
            <option value="">All modules</option>
            <option v-for="module in modules" :key="module" :value="module">{{ moduleLabel(module) }}</option>
          </select>
        </div>
        <div class="col-md-2">
          <input v-model="filters.date_from" type="date" class="form-control form-control-sm" @change="reload" />
        </div>
        <div class="col-md-2">
          <input v-model="filters.date_to" type="date" class="form-control form-control-sm" @change="reload" />
        </div>
        <div class="col-md-1">
          <button type="button" class="btn btn-outline-secondary btn-sm w-100" @click="clearFilters">Clear</button>
        </div>
      </div>

      <div v-if="loading" class="text-center py-4">
        <div class="spinner-border spinner-border-sm text-primary"></div>
      </div>

      <EmptyState
        v-else-if="!items.length"
        title="No activity found"
        description="Actions taken by admins, committee members, and other staff will appear here."
        icon="bi bi-journal-text"
      />

      <DataTable v-else :columns="columns" :rows="items">
        <template #cell-created_at="{ row }">
          <span class="text-muted small">{{ row.created_at }}</span>
        </template>
        <template #cell-user="{ row }">
          <div v-if="row.user">
            <div class="fw-semibold">{{ row.user.name }}</div>
            <div class="text-muted small">{{ row.user.email }}</div>
            <AppBadge v-for="role in row.user.roles" :key="role" variant="primary" class="me-1 mt-1">
              {{ roleLabel(role) }}
            </AppBadge>
          </div>
          <span v-else class="text-muted small">System</span>
        </template>
        <template #cell-module="{ row }">
          <AppBadge variant="info">{{ moduleLabel(row.module) }}</AppBadge>
        </template>
        <template #cell-description="{ row }">
          <div>{{ row.description || '—' }}</div>
          <router-link
            v-if="row.project"
            :to="`/projects/${row.project.id}`"
            class="small"
            target="_blank"
            rel="noopener"
          >
            {{ row.project.title }}
          </router-link>
        </template>
      </DataTable>

      <div v-if="meta.last_page > 1" class="d-flex justify-content-between align-items-center mt-3">
        <div class="text-muted small">Page {{ meta.current_page }} of {{ meta.last_page }} ({{ meta.total }} entries)</div>
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
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DataTable from '@/components/table/DataTable.vue'
import { fetchActivityLogs } from '@/api/activityLogs'
import { formatApiError } from '@/utils/apiErrors'

const items = ref([])
const modules = ref([])
const loading = ref(false)
const loadError = ref('')
const meta = reactive({ current_page: 1, last_page: 1, per_page: 20, total: 0 })

const filters = reactive({
  search: '',
  module: '',
  date_from: '',
  date_to: '',
})

const columns = [
  { key: 'created_at', label: 'When' },
  { key: 'user', label: 'Who' },
  { key: 'module', label: 'Module' },
  { key: 'action', label: 'Action' },
  { key: 'description', label: 'Details' },
]

const moduleLabel = (module) =>
  (module || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

const roleLabel = (role) =>
  (role || '').replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

let debounceTimer = null
const debouncedReload = () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(reload, 400)
}

const load = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchActivityLogs({
      page: meta.current_page,
      per_page: meta.per_page,
      search: filters.search || undefined,
      module: filters.module || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
    })
    items.value = res.data?.logs || []
    Object.assign(meta, res.data?.meta || {})
    if (res.data?.modules?.length) {
      modules.value = res.data.modules
    }
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load activity log.')
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

const clearFilters = () => {
  filters.search = ''
  filters.module = ''
  filters.date_from = ''
  filters.date_to = ''
  reload()
}

onMounted(load)
</script>
