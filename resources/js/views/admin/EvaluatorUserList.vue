<template>
  <div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h5 class="mb-1">Evaluators</h5>
        <p class="text-muted small mb-0">Only faculty members can be assigned as evaluators.</p>
      </div>
      <div class="d-flex gap-2">
        <router-link to="/admin/evaluators" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-bar-chart me-1"></i> Workload Overview
        </router-link>
        <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
          <i class="bi bi-plus-lg me-1"></i> Add Evaluator
        </button>
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
            @keyup.enter="applyFilters"
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
        <div class="col-md-3 d-flex gap-2">
          <button type="button" class="btn btn-primary btn-sm" @click="applyFilters">Apply</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilters">Reset</button>
        </div>
      </div>
    </AppCard>

    <DataTable title="All Evaluators" :columns="columns" :rows="users" :mobile-card-view="true">
      <template #cell-name="{ row }">
        <div class="avatar-cell">
          <img :src="avatarUrl(row.name)" :alt="row.name" />
          <div>
            <div class="name">{{ row.name }}</div>
            <div class="email">{{ row.email }}</div>
          </div>
        </div>
      </template>
      <template #cell-is_faculty_member="{ row }">
        <AppBadge :variant="row.is_faculty_member ? 'success' : 'danger'">
          {{ row.is_faculty_member ? 'Faculty Member' : 'Not Faculty' }}
        </AppBadge>
      </template>
      <template #cell-program="{ row }">
        <span class="text-muted small">{{ row.program_name || row.program || '—' }}</span>
      </template>
      <template #cell-roles="{ row }">
        <AppBadge v-for="role in row.roles" :key="role" variant="primary" class="me-1 mb-1">{{ roleLabel(role) }}</AppBadge>
      </template>
      <template #cell-direct_permissions="{ row }">
        <AppBadge
          v-for="perm in (row.direct_permissions || []).slice(0, 2)"
          :key="perm"
          variant="info"
          class="me-1 mb-1"
        >
          {{ perm }}
        </AppBadge>
        <span v-if="(row.direct_permissions || []).length > 2" class="text-muted small">
          +{{ row.direct_permissions.length - 2 }}
        </span>
        <span v-if="!(row.direct_permissions || []).length" class="text-muted">—</span>
      </template>
      <template #cell-status="{ row }">
        <AppBadge :variant="statusVariant(row.status)">{{ row.status }}</AppBadge>
      </template>
      <template #actions="{ row }">
        <button type="button" class="action-btn" title="Edit" @click="openEdit(row)">
          <i class="bi bi-pencil"></i>
        </button>
        <button type="button" class="action-btn danger" title="Delete" @click="removeUser(row)">
          <i class="bi bi-trash"></i>
        </button>
      </template>
    </DataTable>
  </div>

  <div v-if="showModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">{{ editingUser ? 'Edit Evaluator' : 'Add Evaluator' }}</h5>
          <button type="button" class="btn-close" @click="closeModal"></button>
        </div>
        <form @submit.prevent="saveUser">
          <div class="modal-body">
            <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
            <div v-if="!editingUser" class="mb-3">
              <label class="form-label d-block">Assignment Type</label>
              <div class="form-check">
                <input id="evaluator-new" v-model="createMode" class="form-check-input" type="radio" value="new" />
                <label class="form-check-label" for="evaluator-new">Create new faculty evaluator</label>
              </div>
              <div class="form-check">
                <input id="evaluator-existing" v-model="createMode" class="form-check-input" type="radio" value="existing" />
                <label class="form-check-label" for="evaluator-existing">Assign evaluator role to existing faculty</label>
              </div>
            </div>

            <div v-if="!editingUser && createMode === 'existing'" class="mb-3">
              <label class="form-label">Faculty Member</label>
              <select v-model="selectedFacultyId" class="form-select" required>
                <option :value="null">Select faculty member</option>
                <option v-for="faculty in facultyOptions" :key="faculty.id" :value="faculty.id">
                  {{ faculty.name }} ({{ faculty.email }})
                </option>
              </select>
            </div>

            <template v-if="editingUser || createMode === 'new'">
              <div class="mb-3">
                <label class="form-label">Name</label>
                <input v-model="form.name" type="text" class="form-control" required />
              </div>
              <div class="mb-3">
                <label class="form-label">Email</label>
                <input v-model="form.email" type="email" class="form-control" required />
              </div>
              <div class="mb-3">
                <label class="form-label">Phone</label>
                <input v-model="form.phone" type="text" class="form-control" />
              </div>
              <div v-if="!editingUser" class="mb-3">
                <label class="form-label">Password</label>
                <input v-model="form.password" type="password" class="form-control" required />
              </div>
              <div class="mb-3">
                <label class="form-label">Status</label>
                <select v-model="form.status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="suspended">Suspended</option>
                </select>
              </div>
              <UserAccessFields
                v-model:roles="form.roles"
                v-model:direct-permissions="form.direct_permissions"
                :available-roles="evaluatorRoles"
                :locked-roles="['faculty', 'evaluator']"
                :permission-groups="permissionGroups"
                id-prefix="evaluator-user"
              />
            </template>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="closeModal">Cancel</button>
            <button type="submit" class="btn btn-primary" :disabled="saving">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, computed } from 'vue'
import AppCard from '@/components/ui/AppCard.vue'
import DataTable from '@/components/table/DataTable.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import UserAccessFields from '@/components/admin/UserAccessFields.vue'
import { formatApiError } from '@/utils/apiErrors'
import {
  fetchEvaluatorUsers,
  createEvaluatorUser,
  updateEvaluatorUser,
  deleteEvaluatorUser,
} from '@/api/evaluatorUsers'
import { fetchFacultyUsers } from '@/api/facultyUsers'
import { fetchPermissions } from '@/api/permissions'
import { EVALUATOR_FACULTY_ROLES, roleLabel } from '@/utils/roles'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/composables/useToast'

const authStore = useAuthStore()

const users = ref([])
const facultyOptions = ref([])
const permissionGroups = ref([])
const showModal = ref(false)
const editingUser = ref(null)
const saving = ref(false)
const formError = ref('')

const evaluatorRoles = EVALUATOR_FACULTY_ROLES
const createMode = ref('new')
const selectedFacultyId = ref(null)

const filters = reactive({
  search: '',
  status: '',
  program_id: '',
})

const programFilterOptions = computed(() => authStore.accessiblePrograms || [])

const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  roles: ['faculty', 'evaluator'],
  direct_permissions: [],
  status: 'active',
})

const columns = [
  { key: 'name', label: 'Evaluator' },
  { key: 'program', label: 'Program' },
  { key: 'is_faculty_member', label: 'Faculty' },
  { key: 'roles', label: 'Roles' },
  { key: 'direct_permissions', label: 'Direct Permissions' },
  { key: 'status', label: 'Status' },
]

const avatarUrl = (name) =>
  `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=7367f0&color=fff`

const statusVariant = (status) => ({
  active: 'success',
  inactive: 'secondary',
  suspended: 'danger',
}[status] || 'secondary')

const buildParams = () => {
  const params = { per_page: 100 }
  if (filters.search.trim()) params.search = filters.search.trim()
  if (filters.status) params.status = filters.status
  if (filters.program_id) params.program_id = filters.program_id
  return params
}

const loadUsers = async () => {
  const res = await fetchEvaluatorUsers(buildParams())
  users.value = res.data.users
}

const loadFacultyOptions = async () => {
  const res = await fetchFacultyUsers({ per_page: 200 })
  facultyOptions.value = (res.data.users || []).filter((user) => !user.is_evaluator)
}

const applyFilters = () => loadUsers()

const resetFilters = () => {
  filters.search = ''
  filters.status = ''
  filters.program_id = ''
  loadUsers()
}

const resetForm = () => {
  form.name = ''
  form.email = ''
  form.phone = ''
  form.password = ''
  form.roles = ['faculty', 'evaluator']
  form.direct_permissions = []
  form.status = 'active'
  formError.value = ''
}

const openCreate = () => {
  editingUser.value = null
  resetForm()
  createMode.value = 'new'
  selectedFacultyId.value = null
  loadFacultyOptions()
  showModal.value = true
}

const openEdit = (user) => {
  editingUser.value = user
  form.name = user.name
  form.email = user.email
  form.phone = user.phone || ''
  form.roles = [...(user.roles || ['faculty', 'evaluator'])]
  form.direct_permissions = [...(user.direct_permissions || [])]
  form.status = user.status
  formError.value = ''
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveUser = async () => {
  if (!form.roles.includes('evaluator') || !form.roles.includes('faculty')) {
    formError.value = 'Evaluator must be a faculty member.'
    return
  }

  saving.value = true
  formError.value = ''

  if (!editingUser.value && createMode.value === 'existing') {
    if (!selectedFacultyId.value) {
      formError.value = 'Select a faculty member.'
      saving.value = false
      return
    }

    const faculty = facultyOptions.value.find((user) => user.id === selectedFacultyId.value)
    if (!faculty) {
      formError.value = 'Selected faculty member was not found.'
      saving.value = false
      return
    }

    try {
      await updateEvaluatorUser(faculty.id, {
        name: faculty.name,
        email: faculty.email,
        phone: faculty.phone,
        roles: [...new Set([...(faculty.roles || ['faculty']), 'evaluator'])],
        permissions: faculty.direct_permissions || [],
        status: faculty.status,
      })
      closeModal()
      await loadUsers()
    } catch (err) {
      formError.value = formatApiError(err, 'Save failed.')
    } finally {
      saving.value = false
    }
    return
  }

  const payload = {
    name: form.name,
    email: form.email,
    phone: form.phone,
    roles: form.roles,
    permissions: form.direct_permissions,
    status: form.status,
  }
  try {
    if (editingUser.value) {
      await updateEvaluatorUser(editingUser.value.id, payload)
    } else {
      await createEvaluatorUser({ ...payload, password: form.password })
    }
    closeModal()
    await loadUsers()
  } catch (err) {
    formError.value = formatApiError(err, 'Save failed.')
  } finally {
    saving.value = false
  }
}

const removeUser = async (user) => {
  if (!confirm(`Delete evaluator ${user.name}?`)) return
  try {
    await deleteEvaluatorUser(user.id)
    await loadUsers()
  } catch (err) {
    toast.error(err.response?.data?.message || 'Delete failed.')
  }
}

onMounted(async () => {
  const permsRes = await fetchPermissions()
  permissionGroups.value = permsRes.data.groups || []
  await loadUsers()
})
</script>
