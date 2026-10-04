<template>
  <div>
    <PageHeader title="Roles" subtitle="Manage roles and assign permissions" breadcrumb="Roles">
      <template #actions>
        <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
          <i class="bi bi-plus-lg me-1"></i> Add Role
        </button>
      </template>
    </PageHeader>

    <div v-if="loadError" class="alert alert-danger">{{ loadError }}</div>

    <DataTable title="All Roles" :columns="columns" :rows="roles">
      <template #cell-name="{ row }">
        {{ roleLabel(row.name) }}
      </template>
      <template #cell-permissions="{ row }">
        <AppBadge v-for="perm in (row.permissions || []).slice(0, 3)" :key="perm" variant="info" class="me-1 mb-1">
          {{ perm }}
        </AppBadge>
        <span v-if="(row.permissions || []).length > 3" class="text-muted small">
          +{{ row.permissions.length - 3 }} more
        </span>
      </template>
      <template #actions="{ row }">
        <button type="button" class="action-btn" title="Edit" @click="openEdit(row)">
          <i class="bi bi-pencil"></i>
        </button>
        <button
          v-if="!isSystemRole(row.name)"
          type="button"
          class="action-btn danger"
          title="Delete"
          @click="removeRole(row)"
        >
          <i class="bi bi-trash"></i>
        </button>
      </template>
    </DataTable>

    <div v-if="showModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
          <div class="modal-header">
            <h5 class="modal-title">{{ editingRole ? 'Edit Role' : 'Add Role' }}</h5>
            <button type="button" class="btn-close" @click="closeModal"></button>
          </div>
          <form @submit.prevent="saveRole">
            <div class="modal-body">
              <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
              <div class="mb-3">
                <label class="form-label">Role Name</label>
                <input
                  v-model="form.name"
                  type="text"
                  class="form-control"
                  required
                  :disabled="editingRole?.name === 'fyp-committee-head'"
                />
              </div>
              <PermissionPicker
                v-model="form.permissions"
                :groups="permissionGroups"
                title="Permissions"
                :show-search="true"
                id-prefix="role"
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
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import DataTable from '@/components/table/DataTable.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import PermissionPicker from '@/components/admin/PermissionPicker.vue'
import { formatApiError } from '@/utils/apiErrors'
import { fetchRoles, createRole, updateRole, deleteRole } from '@/api/roles'
import { toast } from '@/composables/useToast'
import { fetchPermissions } from '@/api/permissions'
import { SYSTEM_ROLES, roleLabel } from '@/utils/roles'

const roles = ref([])
const permissionGroups = ref([])
const showModal = ref(false)
const editingRole = ref(null)
const saving = ref(false)
const formError = ref('')

const form = reactive({
  name: '',
  permissions: [],
})

const columns = [
  { key: 'name', label: 'Role' },
  { key: 'permissions', label: 'Permissions' },
]

const isSystemRole = (name) => SYSTEM_ROLES.includes(name)

const loadError = ref('')

const loadData = async () => {
  loadError.value = ''
  try {
    const [rolesRes, permsRes] = await Promise.all([fetchRoles(), fetchPermissions()])
    roles.value = rolesRes.data
    permissionGroups.value = permsRes.data.groups || []
  } catch (err) {
    loadError.value = formatApiError(err, 'Could not load roles.')
  }
}

const openCreate = () => {
  editingRole.value = null
  form.name = ''
  form.permissions = []
  formError.value = ''
  showModal.value = true
}

const openEdit = (role) => {
  editingRole.value = role
  form.name = role.name
  form.permissions = [...(role.permissions || [])]
  formError.value = ''
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveRole = async () => {
  saving.value = true
  formError.value = ''
  try {
    if (editingRole.value) {
      await updateRole(editingRole.value.id, { name: form.name, permissions: form.permissions })
    } else {
      await createRole({ name: form.name, permissions: form.permissions })
    }
    closeModal()
    await loadData()
  } catch (err) {
    formError.value = err.response?.data?.message || 'Save failed.'
  } finally {
    saving.value = false
  }
}

const removeRole = async (role) => {
  if (!confirm(`Delete role ${role.name}?`)) return
  try {
    await deleteRole(role.id)
    await loadData()
  } catch (err) {
    toast.error(err.response?.data?.message || 'Delete failed.')
  }
}

onMounted(loadData)
</script>
