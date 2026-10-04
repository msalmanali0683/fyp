<template>
  <div>
    <PageHeader
      title="Programs & Departments"
      subtitle="Manage academic departments, programs, and cross-program access"
      breadcrumb="Programs"
    />

    <div class="row g-3 mb-3">
      <div class="col-lg-6">
        <AppCard title="Departments">
          <template #header>
            <button type="button" class="btn btn-primary btn-sm" @click="openDepartmentCreate">
              <i class="bi bi-plus-lg me-1"></i> Add
            </button>
          </template>
          <DataTable :columns="departmentColumns" :rows="departments" :mobile-card-view="true">
            <template #cell-is_active="{ row }">
              <AppBadge :variant="row.is_active ? 'success' : 'secondary'">
                {{ row.is_active ? 'Active' : 'Inactive' }}
              </AppBadge>
            </template>
            <template #cell-programs_count="{ row }">
              {{ row.programs_count ?? row.programs?.length ?? 0 }}
            </template>
            <template #actions="{ row }">
              <button type="button" class="action-btn" title="Edit" @click="openDepartmentEdit(row)">
                <i class="bi bi-pencil"></i>
              </button>
            </template>
          </DataTable>
        </AppCard>
      </div>

      <div class="col-lg-6">
        <AppCard title="Programs">
          <template #header>
            <button type="button" class="btn btn-primary btn-sm" @click="openProgramCreate">
              <i class="bi bi-plus-lg me-1"></i> Add
            </button>
          </template>
          <DataTable :columns="programColumns" :rows="programs" :mobile-card-view="true">
            <template #cell-department_name="{ row }">
              {{ row.department_name || row.department?.name || '—' }}
            </template>
            <template #cell-is_active="{ row }">
              <AppBadge :variant="row.is_active ? 'success' : 'secondary'">
                {{ row.is_active ? 'Active' : 'Inactive' }}
              </AppBadge>
            </template>
            <template #actions="{ row }">
              <button type="button" class="action-btn" title="Edit" @click="openProgramEdit(row)">
                <i class="bi bi-pencil"></i>
              </button>
            </template>
          </DataTable>
        </AppCard>
      </div>
    </div>

    <AppCard title="Cross-Program Access Grants">
      <p class="text-muted small mb-3">
        Program heads can grant faculty access to another program. Global admins can manage all grants.
      </p>
      <div class="row g-3 align-items-end mb-3 filter-toolbar">
        <div class="col-md-6">
          <label class="form-label">Program</label>
          <select v-model="grantProgramId" class="form-select" @change="loadGrants">
            <option :value="null">Select program</option>
            <option v-for="program in programs" :key="program.id" :value="program.id">
              {{ program.name }}
            </option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Grant access to faculty (email)</label>
          <div class="input-group">
            <input
              v-model="grantEmail"
              type="email"
              class="form-control"
              placeholder="faculty@example.com"
              :disabled="!grantProgramId || granting"
            />
            <button
              type="button"
              class="btn btn-primary"
              :disabled="!grantProgramId || !grantEmail.trim() || granting"
              @click="submitGrant"
            >
              Grant
            </button>
          </div>
        </div>
      </div>

      <div v-if="grantError" class="alert alert-danger py-2">{{ grantError }}</div>

      <DataTable
        v-if="grantProgramId"
        title="Active Grants"
        :columns="grantColumns"
        :rows="grants"
        :mobile-card-view="true"
      >
        <template #cell-grantee="{ row }">
          <div>{{ row.grantee?.name || '—' }}</div>
          <div class="text-muted small">{{ row.grantee?.email }}</div>
        </template>
        <template #cell-granted_by="{ row }">
          {{ row.granted_by?.name || '—' }}
        </template>
        <template #actions="{ row }">
          <button type="button" class="action-btn danger" title="Revoke" @click="revokeGrant(row)">
            <i class="bi bi-x-lg"></i>
          </button>
        </template>
      </DataTable>
    </AppCard>

    <div v-if="showDeptModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header">
            <h5 class="modal-title">{{ editingDepartment ? 'Edit Department' : 'Add Department' }}</h5>
            <button type="button" class="btn-close" @click="closeDepartmentModal"></button>
          </div>
          <form @submit.prevent="saveDepartment">
            <div class="modal-body">
              <div v-if="deptFormError" class="alert alert-danger py-2">{{ deptFormError }}</div>
              <div class="mb-3">
                <label class="form-label">Name</label>
                <input v-model="deptForm.name" type="text" class="form-control" required />
              </div>
              <div class="form-check form-switch">
                <input id="dept-active" v-model="deptForm.is_active" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="dept-active">Active</label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" @click="closeDepartmentModal">Cancel</button>
              <button type="submit" class="btn btn-primary" :disabled="deptSaving">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div v-if="showProgramModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header">
            <h5 class="modal-title">{{ editingProgram ? 'Edit Program' : 'Add Program' }}</h5>
            <button type="button" class="btn-close" @click="closeProgramModal"></button>
          </div>
          <form @submit.prevent="saveProgram">
            <div class="modal-body">
              <div v-if="programFormError" class="alert alert-danger py-2">{{ programFormError }}</div>
              <div class="mb-3">
                <label class="form-label">Department</label>
                <select v-model="programForm.department_id" class="form-select" required>
                  <option :value="null">Select department</option>
                  <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                    {{ dept.name }}
                  </option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Program Name</label>
                <input v-model="programForm.name" type="text" class="form-control" required />
              </div>
              <div class="mb-3">
                <label class="form-label">Code</label>
                <input v-model="programForm.code" type="text" class="form-control" maxlength="20" required />
                <div class="form-text">Short code for internal use (e.g. CS, DS).</div>
              </div>
              <div class="form-check form-switch">
                <input id="program-active" v-model="programForm.is_active" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="program-active">Active</label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" @click="closeProgramModal">Cancel</button>
              <button type="submit" class="btn btn-primary" :disabled="programSaving">Save</button>
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
import AppCard from '@/components/ui/AppCard.vue'
import DataTable from '@/components/table/DataTable.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import { formatApiError } from '@/utils/apiErrors'
import {
  fetchDepartments,
  createDepartment,
  updateDepartment,
  fetchPrograms,
  createProgram,
  updateProgram,
  fetchProgramAccessGrants,
  grantProgramAccess,
  revokeProgramAccess,
} from '@/api/programs'
import api from '@/api/axios'

const departments = ref([])
const programs = ref([])
const grants = ref([])

const showDeptModal = ref(false)
const showProgramModal = ref(false)
const editingDepartment = ref(null)
const editingProgram = ref(null)
const deptSaving = ref(false)
const programSaving = ref(false)
const deptFormError = ref('')
const programFormError = ref('')

const grantProgramId = ref(null)
const grantEmail = ref('')
const grantError = ref('')
const granting = ref(false)

const deptForm = reactive({ name: '', is_active: true })
const programForm = reactive({
  department_id: null,
  name: '',
  code: '',
  is_active: true,
})

const departmentColumns = [
  { key: 'name', label: 'Department' },
  { key: 'programs_count', label: 'Programs' },
  { key: 'is_active', label: 'Status' },
]

const programColumns = [
  { key: 'name', label: 'Program' },
  { key: 'department_name', label: 'Department' },
  { key: 'code', label: 'Code' },
  { key: 'is_active', label: 'Status' },
]

const grantColumns = [
  { key: 'grantee', label: 'Faculty' },
  { key: 'granted_by', label: 'Granted By' },
  { key: 'created_at', label: 'Date' },
]

const loadDepartments = async () => {
  const res = await fetchDepartments()
  departments.value = res.data?.departments || []
}

const loadPrograms = async () => {
  const res = await fetchPrograms({ all: true, active_only: false })
  programs.value = res.data?.programs || []
}

const loadGrants = async () => {
  grantError.value = ''
  grants.value = []
  if (!grantProgramId.value) return
  try {
    const res = await fetchProgramAccessGrants(grantProgramId.value)
    grants.value = res.data?.grants || []
  } catch (err) {
    grantError.value = formatApiError(err, 'Could not load grants.')
  }
}

const openDepartmentCreate = () => {
  editingDepartment.value = null
  deptForm.name = ''
  deptForm.is_active = true
  deptFormError.value = ''
  showDeptModal.value = true
}

const openDepartmentEdit = (dept) => {
  editingDepartment.value = dept
  deptForm.name = dept.name
  deptForm.is_active = dept.is_active !== false
  deptFormError.value = ''
  showDeptModal.value = true
}

const closeDepartmentModal = () => {
  showDeptModal.value = false
}

const saveDepartment = async () => {
  deptSaving.value = true
  deptFormError.value = ''
  try {
    if (editingDepartment.value) {
      await updateDepartment(editingDepartment.value.id, deptForm)
    } else {
      await createDepartment(deptForm)
    }
    closeDepartmentModal()
    await loadDepartments()
    await loadPrograms()
  } catch (err) {
    deptFormError.value = formatApiError(err, 'Save failed.')
  } finally {
    deptSaving.value = false
  }
}

const openProgramCreate = () => {
  editingProgram.value = null
  programForm.department_id = departments.value[0]?.id ?? null
  programForm.name = ''
  programForm.code = ''
  programForm.is_active = true
  programFormError.value = ''
  showProgramModal.value = true
}

const openProgramEdit = (program) => {
  editingProgram.value = program
  programForm.department_id = program.department_id || program.department?.id || null
  programForm.name = program.name
  programForm.code = program.code
  programForm.is_active = program.is_active !== false
  programFormError.value = ''
  showProgramModal.value = true
}

const closeProgramModal = () => {
  showProgramModal.value = false
}

const saveProgram = async () => {
  programSaving.value = true
  programFormError.value = ''
  try {
    if (editingProgram.value) {
      await updateProgram(editingProgram.value.id, programForm)
    } else {
      await createProgram(programForm)
    }
    closeProgramModal()
    await loadPrograms()
    await loadDepartments()
  } catch (err) {
    programFormError.value = formatApiError(err, 'Save failed.')
  } finally {
    programSaving.value = false
  }
}

const findFacultyByEmail = async (email) => {
  const { data } = await api.get('/api/users', {
    params: { search: email, only_role: 'faculty', per_page: 10 },
  })
  const users = data.data?.users || []
  return users.find((u) => u.email?.toLowerCase() === email.toLowerCase())
}

const submitGrant = async () => {
  granting.value = true
  grantError.value = ''
  try {
    const faculty = await findFacultyByEmail(grantEmail.value.trim())
    if (!faculty) {
      grantError.value = 'No faculty member found with that email.'
      return
    }
    await grantProgramAccess(grantProgramId.value, faculty.id)
    grantEmail.value = ''
    await loadGrants()
  } catch (err) {
    grantError.value = formatApiError(err, 'Could not grant access.')
  } finally {
    granting.value = false
  }
}

const revokeGrant = async (grant) => {
  if (!confirm(`Revoke access for ${grant.grantee?.email}?`)) return
  try {
    await revokeProgramAccess(grantProgramId.value, grant.id)
    await loadGrants()
  } catch (err) {
    grantError.value = formatApiError(err, 'Could not revoke access.')
  }
}

onMounted(async () => {
  await loadDepartments()
  await loadPrograms()
})
</script>
