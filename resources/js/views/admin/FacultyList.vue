<template>
  <div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 list-page-toolbar">
      <div>
        <h5 class="mb-1">Faculty Members</h5>
        <p class="text-muted small mb-0">Manage faculty accounts and assign additional roles (supervisor, evaluator, committee, admin, etc.).</p>
      </div>
      <div class="d-flex flex-wrap gap-2 list-page-toolbar__actions">
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="downloadTemplate">
          <i class="bi bi-download me-1"></i> Download Template
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm" @click="openImport">
          <i class="bi bi-upload me-1"></i> Import Excel
        </button>
        <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
          <i class="bi bi-plus-lg me-1"></i> Add Faculty
        </button>
      </div>
    </div>

    <input
      ref="importInput"
      type="file"
      class="d-none"
      accept=".xlsx,.xls,.csv"
      @change="handleImportFile"
    />

    <AppCard title="Filter Faculty" class="mb-3">
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

    <DataTable title="All Faculty" :columns="columns" :rows="users" :mobile-card-view="true">
      <template #cell-name="{ row }">
        <div class="avatar-cell">
          <img :src="avatarUrl(row.name)" :alt="row.name" />
          <div>
            <div class="name">{{ row.name }}</div>
            <div class="email">{{ row.email }}</div>
          </div>
        </div>
      </template>
      <template #cell-sap_id="{ row }">
        {{ row.sap_id || row.registration_no || '-' }}
      </template>
      <template #cell-program="{ row }">
        {{ row.program_name || row.program || '—' }}
      </template>
      <template #cell-is_supervisor="{ row }">
        <div class="form-check form-switch mb-0">
          <input
            :id="`supervisor-toggle-${row.id}`"
            class="form-check-input"
            type="checkbox"
            role="switch"
            :checked="row.is_supervisor"
            :disabled="togglingId === row.id"
            @change="toggleFacultyRole(row, 'supervisor')"
          />
        </div>
      </template>
      <template #cell-is_evaluator="{ row }">
        <div class="form-check form-switch mb-0">
          <input
            :id="`evaluator-toggle-${row.id}`"
            class="form-check-input"
            type="checkbox"
            role="switch"
            :checked="row.is_evaluator"
            :disabled="togglingId === row.id"
            @change="toggleFacultyRole(row, 'evaluator')"
          />
        </div>
      </template>
      <template #cell-status="{ row }">
        <AppBadge :variant="statusVariant(row.status)">{{ row.status }}</AppBadge>
      </template>
      <template #actions="{ row }">
        <button type="button" class="action-btn" title="Edit" @click="openEdit(row)">
          <i class="bi bi-pencil"></i>
        </button>
        <button type="button" class="action-btn danger" title="Remove Faculty" @click="removeUser(row)">
          <i class="bi bi-trash"></i>
        </button>
      </template>
    </DataTable>
  </div>

  <div v-if="showModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">{{ editingUser ? 'Edit Faculty Member' : 'Add Faculty Member' }}</h5>
          <button type="button" class="btn-close" @click="closeModal"></button>
        </div>
        <form @submit.prevent="saveUser">
          <div class="modal-body">
            <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
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
            <ProgramDepartmentFields
              v-model:department-id="form.department_id"
              v-model:program-id="form.program_id"
              :required="true"
            />
            <div class="mb-3 mt-3">
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
              :available-roles="facultyRoles"
              :locked-roles="['faculty']"
              :permission-groups="permissionGroups"
              id-prefix="faculty-user"
            />
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" @click="closeModal">Cancel</button>
            <button type="submit" class="btn btn-primary" :disabled="saving">Save</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div v-if="showImportModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">Import Faculty</h5>
          <button type="button" class="btn-close" @click="closeImportModal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Assign imported faculty to a program. Default password: <strong>password</strong></p>
          <label class="form-label">Program</label>
          <select v-model="importProgramId" class="form-select" required>
            <option :value="null">Select program</option>
            <option v-for="program in programFilterOptions" :key="program.id" :value="program.id">
              {{ program.name }}
            </option>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" @click="closeImportModal">Cancel</button>
          <button type="button" class="btn btn-primary" :disabled="!importProgramId" @click="confirmImport">
            Choose File
          </button>
        </div>
      </div>
    </div>
  </div>

  <div v-if="showImportResult" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">Faculty Import Results</h5>
          <button type="button" class="btn-close" @click="closeImportResult"></button>
        </div>
        <div class="modal-body">
          <div v-if="importResultMessage" class="alert alert-info py-2">{{ importResultMessage }}</div>
          <p class="small text-muted mb-3">
            Default password for imported faculty: <strong>password</strong>
          </p>

          <div v-if="importCreated.length" class="mb-4">
            <h6 class="mb-2">Imported ({{ importCreated.length }})</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>SAP ID</th>
                    <th>Name</th>
                    <th>Email</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in importCreated" :key="item.email">
                    <td>{{ item.sap_id }}</td>
                    <td>{{ item.name }}</td>
                    <td>{{ item.email }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-if="importErrors.length">
            <h6 class="mb-2 text-danger">Errors ({{ importErrors.length }})</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Row</th>
                    <th>SAP ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Issue</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in importErrors" :key="`${item.row}-${item.email || item.message}`">
                    <td>{{ item.row }}</td>
                    <td>{{ item.sap_id || '-' }}</td>
                    <td>{{ item.name || '-' }}</td>
                    <td>{{ item.email || '-' }}</td>
                    <td class="text-danger">{{ item.message }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" @click="closeImportResult">Done</button>
        </div>
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
import ProgramDepartmentFields from '@/components/admin/ProgramDepartmentFields.vue'
import { formatApiError } from '@/utils/apiErrors'
import { toast } from '@/composables/useToast'
import {
  fetchFacultyUsers,
  createFacultyUser,
  updateFacultyUser,
  deleteFacultyUser,
  downloadFacultyImportTemplate,
  importFacultyUsers,
} from '@/api/facultyUsers'
import { fetchPermissions } from '@/api/permissions'
import { STAFF_ROLES } from '@/utils/roles'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const users = ref([])
const permissionGroups = ref([])
const showModal = ref(false)
const showImportModal = ref(false)
const showImportResult = ref(false)
const importInput = ref(null)
const importing = ref(false)
const importProgramId = ref(null)
const importResultMessage = ref('')
const importCreated = ref([])
const importErrors = ref([])
const editingUser = ref(null)
const saving = ref(false)
const formError = ref('')
const togglingId = ref(null)

const facultyRoles = STAFF_ROLES

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
  department_id: null,
  program_id: null,
  roles: ['faculty'],
  direct_permissions: [],
  status: 'active',
})

const columns = [
  { key: 'name', label: 'Faculty' },
  { key: 'sap_id', label: 'SAP ID' },
  { key: 'program', label: 'Program' },
  { key: 'is_supervisor', label: 'Supervisor' },
  { key: 'is_evaluator', label: 'Evaluator' },
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
  const res = await fetchFacultyUsers(buildParams())
  users.value = res.data.users
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
  form.department_id = authStore.user?.department_id || null
  form.program_id = authStore.user?.program_id || null
  form.roles = ['faculty']
  form.direct_permissions = []
  form.status = 'active'
  formError.value = ''
}

const openCreate = () => {
  editingUser.value = null
  resetForm()
  showModal.value = true
}

const openEdit = (user) => {
  editingUser.value = user
  form.name = user.name
  form.email = user.email
  form.phone = user.phone || ''
  form.department_id = user.department_id || null
  form.program_id = user.program_id || null
  form.roles = [...(user.roles || ['faculty'])]
  form.direct_permissions = [...(user.direct_permissions || [])]
  form.status = user.status
  formError.value = ''
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveUser = async () => {
  if (!form.roles.includes('faculty')) {
    formError.value = 'Faculty role is required.'
    return
  }

  if (
    (form.roles.includes('supervisor') || form.roles.includes('evaluator'))
    && !form.roles.includes('faculty')
  ) {
    formError.value = 'Supervisor and evaluator roles require faculty membership.'
    return
  }

  if (!form.program_id) {
    formError.value = 'Program is required.'
    return
  }

  saving.value = true
  formError.value = ''
  const payload = {
    name: form.name,
    email: form.email,
    phone: form.phone,
    department_id: form.department_id,
    program_id: form.program_id,
    roles: form.roles,
    permissions: form.direct_permissions,
    status: form.status,
  }
  try {
    if (editingUser.value) {
      await updateFacultyUser(editingUser.value.id, payload)
    } else {
      await createFacultyUser({ ...payload, password: form.password })
    }
    closeModal()
    await loadUsers()
  } catch (err) {
    formError.value = formatApiError(err, 'Save failed.')
  } finally {
    saving.value = false
  }
}

const toggleFacultyRole = async (row, roleName) => {
  const hasRole = roleName === 'supervisor' ? row.is_supervisor : row.is_evaluator
  const newRoles = hasRole
    ? row.roles.filter((r) => r !== roleName)
    : [...row.roles, roleName]

  togglingId.value = row.id
  try {
    const res = await updateFacultyUser(row.id, {
      name: row.name,
      email: row.email,
      phone: row.phone,
      department_id: row.department_id,
      program_id: row.program_id,
      roles: newRoles,
      permissions: row.direct_permissions,
      status: row.status,
    })
    const index = users.value.findIndex((u) => u.id === row.id)
    if (index >= 0 && res.data?.user) {
      users.value[index] = res.data.user
    }
    const roleLabel = roleName === 'supervisor' ? 'Supervisor' : 'Evaluator'
    toast.success(`${roleLabel} role ${hasRole ? 'removed' : 'granted'}.`)
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to update role.'))
  } finally {
    togglingId.value = null
  }
}

const removeUser = async (user) => {
  if (user.is_supervisor || user.is_evaluator) {
    toast.warning('Remove supervisor or evaluator role first from the Supervisors or Evaluators tab.')
    return
  }
  if (!confirm(`Remove faculty member ${user.name}?`)) return
  try {
    await deleteFacultyUser(user.id)
    await loadUsers()
  } catch (err) {
    toast.error(err.response?.data?.message || 'Delete failed.')
  }
}

const downloadTemplate = async () => {
  try {
    await downloadFacultyImportTemplate()
  } catch (err) {
    toast.error(formatApiError(err, 'Could not download template.'))
  }
}

const openImport = () => {
  importProgramId.value = authStore.user?.program_id || programFilterOptions.value[0]?.id || null
  showImportModal.value = true
}

const closeImportModal = () => {
  showImportModal.value = false
}

const confirmImport = () => {
  closeImportModal()
  importInput.value?.click()
}

const handleImportFile = async (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''

  if (!file) {
    return
  }

  importing.value = true
  try {
    const response = await importFacultyUsers(file, importProgramId.value)
    importResultMessage.value = response.message || 'Import completed.'
    importCreated.value = response.data?.created || []
    importErrors.value = response.data?.errors || []
    showImportResult.value = true
    await loadUsers()
  } catch (err) {
    toast.error(formatApiError(err, 'Faculty import failed.'))
  } finally {
    importing.value = false
  }
}

const closeImportResult = () => {
  showImportResult.value = false
  importResultMessage.value = ''
  importCreated.value = []
  importErrors.value = []
}

onMounted(async () => {
  const permsRes = await fetchPermissions()
  permissionGroups.value = permsRes.data.groups || []
  await loadUsers()
})
</script>
