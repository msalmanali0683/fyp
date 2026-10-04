<template>
  <div>
    <PageHeader title="Students" subtitle="Manage student accounts and proposal enrollment" breadcrumb="Students">
      <template #actions>
        <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
          <i class="bi bi-plus-lg me-1"></i> Add Student
        </button>
      </template>
    </PageHeader>

    <AppCard title="Filter Students" class="mb-3">
      <div class="row g-3 align-items-end filter-toolbar">
        <div class="col-md-5">
          <label class="form-label">Search</label>
          <input
            v-model="filters.search"
            type="search"
            class="form-control"
            placeholder="Name, email, SAP ID..."
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
        <div class="col-md-2">
          <label class="form-label">Proposal Enrolled</label>
          <select v-model="filters.is_proposal_enrolled" class="form-select">
            <option value="">All</option>
            <option value="1">Enrolled</option>
            <option value="0">Not enrolled</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Team Status</label>
          <select v-model="filters.in_team" class="form-select">
            <option value="">All</option>
            <option value="1">In team</option>
            <option value="0">Not in team</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Program</label>
          <select v-model="filters.program_id" class="form-select">
            <option value="">All programs</option>
            <option v-for="program in programFilterOptions" :key="program.id" :value="program.id">
              {{ program.name }}
            </option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Proposal Session</label>
          <select v-model="filters.session" class="form-select">
            <option value="">All sessions</option>
            <option v-for="session in sessionFilterOptions" :key="session.id" :value="session.code">
              {{ session.name }} ({{ session.code }})
            </option>
          </select>
        </div>
        <div class="col-md-12 col-lg-auto d-flex gap-2">
          <button type="button" class="btn btn-primary btn-sm" @click="applyFilters">Apply</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilters">Reset</button>
        </div>
      </div>
    </AppCard>

    <DataTable title="All Students" :columns="columns" :rows="students" :mobile-card-view="true">
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
        {{ row.sap_id || row.registration_no || '—' }}
      </template>
      <template #cell-program="{ row }">
        <span class="text-muted small">{{ row.program_name || row.program || '—' }}</span>
      </template>
      <template #cell-session="{ row }">
        <AppBadge v-if="row.session" variant="info">{{ row.session }}</AppBadge>
        <span v-else class="text-muted">—</span>
      </template>
      <template #cell-roles="{ row }">
        <AppBadge v-for="role in row.roles" :key="role" variant="primary" class="me-1 mb-1">{{ roleLabel(role) }}</AppBadge>
      </template>
      <template #cell-is_proposal_enrolled="{ row }">
        <AppBadge :variant="row.is_proposal_enrolled ? 'success' : 'secondary'">
          {{ row.is_proposal_enrolled ? 'Enrolled' : 'Not enrolled' }}
        </AppBadge>
      </template>
      <template #cell-team_status="{ row }">
        <div>
          <AppBadge :variant="teamStatusVariant(row.team_status)">
            {{ row.team_status?.label || 'Not in team' }}
          </AppBadge>
          <div v-if="row.team_status?.project_title" class="text-muted small mt-1">
            {{ row.team_status.project_title }}
          </div>
        </div>
      </template>
      <template #cell-status="{ row }">
        <AppBadge :variant="statusVariant(row.status)">{{ row.status }}</AppBadge>
      </template>
      <template #actions="{ row }">
        <router-link
          v-if="row.team_status?.project_id"
          :to="`/projects/${row.team_status.project_id}`"
          class="action-btn"
          title="View Project"
        >
          <i class="bi bi-eye"></i>
        </router-link>
        <button type="button" class="action-btn" title="Edit" @click="openEdit(row)">
          <i class="bi bi-pencil"></i>
        </button>
        <button type="button" class="action-btn danger" title="Delete" @click="removeStudent(row)">
          <i class="bi bi-trash"></i>
        </button>
      </template>
    </DataTable>

    <EmptyState
      v-if="!students.length"
      title="No students found"
      description="Adjust your filters or import students for the active proposal session."
      icon="bi bi-mortarboard"
    />
  </div>

  <div v-if="showModal" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">{{ editingStudent ? 'Edit Student' : 'Add Student' }}</h5>
          <button type="button" class="btn-close" @click="closeModal"></button>
        </div>
        <form @submit.prevent="saveStudent">
          <div class="modal-body">
            <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Name</label>
                <input v-model="form.name" type="text" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">SAP ID (Roll No)</label>
                <input v-model="form.registration_no" type="text" class="form-control" placeholder="SAP-2024-011" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Email</label>
                <input v-model="form.email" type="email" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Phone</label>
                <input v-model="form.phone" type="text" class="form-control" />
              </div>
              <div class="col-md-6">
                <label class="form-label">Father Name</label>
                <input v-model="form.father_name" type="text" class="form-control" />
              </div>
              <div class="col-12">
                <ProgramDepartmentFields
                  v-model:department-id="form.department_id"
                  v-model:program-id="form.program_id"
                  :required="true"
                />
              </div>
              <div class="col-md-6">
                <ProposalSessionPicker
                  v-model="form.session"
                  :options="sessionOptions"
                  :program-id="form.program_id"
                  label="Proposal Session"
                  hint="Choose the proposal session created for this student's program."
                />
              </div>
              <div v-if="!editingStudent" class="col-md-6">
                <label class="form-label">Password</label>
                <input v-model="form.password" type="password" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Status</label>
                <select v-model="form.status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="suspended">Suspended</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label d-block">Proposal Enrollment</label>
                <div class="form-check form-switch mt-2">
                  <input id="enrolled" v-model="form.is_proposal_enrolled" class="form-check-input" type="checkbox" />
                  <label class="form-check-label" for="enrolled">Enrolled for proposal phase</label>
                </div>
              </div>
            </div>
            <UserAccessFields
              v-model:roles="form.roles"
              v-model:direct-permissions="form.direct_permissions"
              :available-roles="STUDENT_ROLE_OPTIONS"
              :locked-roles="['student']"
              :permission-groups="permissionGroups"
              hide-permissions
              id-prefix="student"
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
import { onMounted, reactive, ref, computed, watch } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import DataTable from '@/components/table/DataTable.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import UserAccessFields from '@/components/admin/UserAccessFields.vue'
import ProgramDepartmentFields from '@/components/admin/ProgramDepartmentFields.vue'
import ProposalSessionPicker from '@/components/admin/ProposalSessionPicker.vue'
import { formatApiError } from '@/utils/apiErrors'
import { fetchStudents, createStudent, updateStudent, deleteStudent } from '@/api/students'
import { fetchPermissions } from '@/api/permissions'
import { fetchProposalSessionOptions } from '@/api/proposalSessions'
import { STUDENT_ROLE_OPTIONS, roleLabel } from '@/utils/roles'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/composables/useToast'

const authStore = useAuthStore()

const students = ref([])
const permissionGroups = ref([])
const sessionOptions = ref([])
const showModal = ref(false)
const editingStudent = ref(null)
const saving = ref(false)
const formError = ref('')

const filters = reactive({
  search: '',
  status: '',
  is_proposal_enrolled: '',
  in_team: '',
  program_id: '',
  session: '',
})

const programFilterOptions = computed(() => authStore.accessiblePrograms || [])
const sessionFilterOptions = ref([])

const form = reactive({
  name: '',
  email: '',
  phone: '',
  password: '',
  registration_no: '',
  father_name: '',
  program: '',
  department: '',
  department_id: null,
  program_id: null,
  session: '',
  is_proposal_enrolled: false,
  roles: ['student'],
  direct_permissions: [],
  status: 'active',
})

const columns = [
  { key: 'name', label: 'Student' },
  { key: 'sap_id', label: 'SAP ID' },
  { key: 'program', label: 'Program' },
  { key: 'session', label: 'Session' },
  { key: 'roles', label: 'Roles' },
  { key: 'is_proposal_enrolled', label: 'Proposal' },
  { key: 'team_status', label: 'Team' },
  { key: 'status', label: 'Status' },
]

const avatarUrl = (name) =>
  `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=7367f0&color=fff`

const statusVariant = (status) => ({
  active: 'success',
  inactive: 'secondary',
  suspended: 'danger',
}[status] || 'secondary')

const teamStatusVariant = (teamStatus) => {
  if (teamStatus?.team_role === 'leader') return 'primary'
  if (teamStatus?.team_role === 'member') return 'info'
  return 'secondary'
}

const buildParams = () => {
  const params = { per_page: 100 }
  if (filters.search.trim()) params.search = filters.search.trim()
  if (filters.status) params.status = filters.status
  if (filters.is_proposal_enrolled !== '') params.is_proposal_enrolled = filters.is_proposal_enrolled
  if (filters.in_team !== '') params.in_team = filters.in_team
  if (filters.program_id) params.program_id = filters.program_id
  if (filters.session) params.session = filters.session
  return params
}

const loadSessionFilterOptions = async () => {
  try {
    const res = await fetchProposalSessionOptions({ include_all: 1 })
    sessionFilterOptions.value = res.data?.sessions || []
  } catch {
    sessionFilterOptions.value = []
  }
}

const loadStudents = async () => {
  const res = await fetchStudents(buildParams())
  students.value = res.data.users
}

const applyFilters = () => loadStudents()

const resetFilters = () => {
  filters.search = ''
  filters.status = ''
  filters.is_proposal_enrolled = ''
  filters.in_team = ''
  filters.program_id = ''
  filters.session = ''
  loadStudents()
}

const resetForm = () => {
  form.name = ''
  form.email = ''
  form.phone = ''
  form.password = ''
  form.registration_no = ''
  form.father_name = ''
  form.program = ''
  form.department = ''
  form.department_id = authStore.user?.department_id || null
  form.program_id = authStore.user?.program_id || null
  form.session = ''
  form.is_proposal_enrolled = false
  form.roles = ['student']
  form.direct_permissions = []
  form.status = 'active'
  formError.value = ''
}

const loadSessionOptions = async (programId = null) => {
  try {
    const params = programId ? { program_id: programId } : {}
    const res = await fetchProposalSessionOptions(params)
    sessionOptions.value = res.data?.sessions || []
  } catch {
    sessionOptions.value = []
  }
}

const openCreate = () => {
  editingStudent.value = null
  resetForm()
  showModal.value = true
  loadSessionOptions(form.program_id)
}

const openEdit = (student) => {
  editingStudent.value = student
  form.name = student.name
  form.email = student.email
  form.phone = student.phone || ''
  form.registration_no = student.registration_no || student.sap_id || ''
  form.father_name = student.father_name || ''
  form.program = student.program || ''
  form.department = student.department || ''
  form.department_id = student.department_id || null
  form.program_id = student.program_id || null
  form.session = student.session || ''
  form.is_proposal_enrolled = !!student.is_proposal_enrolled
  form.roles = ['student']
  form.direct_permissions = []
  form.status = student.status
  formError.value = ''
  showModal.value = true
  loadSessionOptions(form.program_id)
}

const closeModal = () => {
  showModal.value = false
}

const saveStudent = async () => {
  if (!form.roles.length) {
    formError.value = 'Student role is required.'
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
    registration_no: form.registration_no,
    father_name: form.father_name,
    program: form.program,
    department: form.department,
    department_id: form.department_id,
    program_id: form.program_id,
    session: form.session,
    is_proposal_enrolled: form.is_proposal_enrolled,
    roles: form.roles,
    permissions: form.direct_permissions,
    status: form.status,
  }
  try {
    if (editingStudent.value) {
      await updateStudent(editingStudent.value.id, payload)
    } else {
      await createStudent({ ...payload, password: form.password })
    }
    closeModal()
    await loadStudents()
  } catch (err) {
    formError.value = formatApiError(err, 'Save failed.')
  } finally {
    saving.value = false
  }
}

const removeStudent = async (student) => {
  if (!confirm(`Delete student ${student.name}?`)) return
  try {
    await deleteStudent(student.id)
    await loadStudents()
  } catch (err) {
    toast.error(err.response?.data?.message || 'Delete failed.')
  }
}

onMounted(async () => {
  const permsRes = await fetchPermissions()
  permissionGroups.value = permsRes.data.groups || []
  await Promise.all([loadSessionFilterOptions(), loadStudents()])
})

watch(
  () => form.program_id,
  (programId) => {
    if (!showModal.value) {
      return
    }

    loadSessionOptions(programId)
  }
)
</script>
