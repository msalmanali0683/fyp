<template>
  <div>
    <PageHeader title="Users" subtitle="Manage admin and committee staff (excluding students, faculty, supervisors, and evaluators)" breadcrumb="Users">
      <template #actions>
        <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
          <i class="bi bi-plus-lg me-1"></i> Add User
        </button>
      </template>
    </PageHeader>

    <AppCard title="Filter Users" class="mb-3">
      <div class="row g-3 align-items-end filter-toolbar">
        <div class="col-md-5">
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
          <label class="form-label">Role</label>
          <select v-model="filters.role" class="form-select">
            <option value="">All staff roles</option>
            <option v-for="role in CORE_STAFF_ROLES" :key="role.value" :value="role.value">
              {{ role.label }}
            </option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select v-model="filters.status" class="form-select">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
          <button type="button" class="btn btn-primary btn-sm" @click="applyFilters">Apply</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilters">Reset</button>
        </div>
      </div>
    </AppCard>

    <DataTable title="Staff Users" :columns="columns" :rows="users" :mobile-card-view="true">
      <template #cell-name="{ row }">
        <div class="avatar-cell">
          <img :src="avatarUrl(row.name)" :alt="row.name" />
          <div>
            <div class="name">{{ row.name }}</div>
            <div class="email">{{ row.email }}</div>
          </div>
        </div>
      </template>
      <template #cell-roles="{ row }">
        <AppBadge v-for="role in row.roles" :key="role" variant="primary" class="me-1 mb-1">{{ roleLabel(role) }}</AppBadge>
      </template>
      <template #cell-program="{ row }">
        <span class="text-muted small">{{ row.program_name || row.program || '—' }}</span>
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
          <h5 class="modal-title">{{ editingUser ? 'Edit User' : 'Add User' }}</h5>
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
              :required="programRequired"
            />
            <p v-if="!programRequired" class="form-text mt-2 mb-3">
              Program is optional for global admin accounts. Assign a program for committee staff so they only see their program's data.
            </p>
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
              :available-roles="CORE_STAFF_ROLES"
              :permission-groups="permissionGroups"
              id-prefix="staff"
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
</template>

<script setup>
import { onMounted, reactive, ref, computed } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import DataTable from '@/components/table/DataTable.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import UserAccessFields from '@/components/admin/UserAccessFields.vue'
import ProgramDepartmentFields from '@/components/admin/ProgramDepartmentFields.vue'
import { formatApiError } from '@/utils/apiErrors'
import { fetchUsers, createUser, updateUser, deleteUser } from '@/api/users'
import { fetchPermissions } from '@/api/permissions'
import { CORE_STAFF_ROLES, roleLabel } from '@/utils/roles'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/composables/useToast'

const authStore = useAuthStore()

const users = ref([])
const permissionGroups = ref([])
const showModal = ref(false)
const editingUser = ref(null)
const saving = ref(false)
const formError = ref('')

const filters = reactive({
  search: '',
  role: '',
  status: '',
})

const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  role: 'admin',
  roles: ['admin'],
  department_id: null,
  program_id: null,
  direct_permissions: [],
  status: 'active',
})

const programRequired = computed(() =>
  form.roles.some((role) => ['fyp-committee-head', 'fyp-committee-member'].includes(role))
)

const columns = [
  { key: 'name', label: 'User' },
  { key: 'program', label: 'Program' },
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
  const params = { per_page: 100, exclude_roles: 'student,faculty,supervisor,evaluator' }
  if (filters.search.trim()) params.search = filters.search.trim()
  if (filters.role) params.role = filters.role
  if (filters.status) params.status = filters.status
  return params
}

const loadUsers = async () => {
  const res = await fetchUsers(buildParams())
  users.value = res.data.users
}

const applyFilters = () => loadUsers()

const resetFilters = () => {
  filters.search = ''
  filters.role = ''
  filters.status = ''
  loadUsers()
}

const resetForm = () => {
  form.name = ''
  form.email = ''
  form.phone = ''
  form.password = ''
  form.role = 'admin'
  form.roles = ['admin']
  form.department_id = authStore.user?.department_id || null
  form.program_id = authStore.user?.program_id || null
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
  form.role = user.roles?.[0] || 'admin'
  form.roles = [...(user.roles || ['admin'])]
  form.department_id = user.department_id || null
  form.program_id = user.program_id || null
  form.direct_permissions = [...(user.direct_permissions || [])]
  form.status = user.status
  formError.value = ''
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveUser = async () => {
  if (!form.roles.length) {
    formError.value = 'Select at least one role.'
    return
  }

  if (programRequired.value && !form.program_id) {
    formError.value = 'Program is required for committee staff.'
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
      await updateUser(editingUser.value.id, payload)
    } else {
      await createUser({ ...payload, password: form.password })
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
  if (!confirm(`Delete user ${user.name}?`)) return
  try {
    await deleteUser(user.id)
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
