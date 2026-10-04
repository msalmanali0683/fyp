<template>
  <div>
    <PageHeader
      :title="session ? `${session.name} (${session.code})` : 'Session Details'"
      :subtitle="session ? session.program_name : 'Loading session...'"
      breadcrumb="Proposal Sessions"
    >
      <template #actions>
        <router-link to="/admin/proposal-sessions" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>
          Back to Sessions
        </router-link>
      </template>
    </PageHeader>

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <template v-else-if="session">
      <div class="session-overview mb-4">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <AppBadge v-if="session.is_current_proposal_session" variant="primary">Current</AppBadge>
          <AppBadge variant="info">{{ session.lifecycle_phase_label || 'Proposal Phase' }}</AppBadge>
          <AppBadge :variant="session.is_submission_open ? 'success' : 'secondary'">
            {{ session.is_submission_open ? 'New reg. open' : 'New reg. closed' }}
          </AppBadge>
          <AppBadge :variant="session.is_fully_locked ? 'danger' : 'secondary'">
            {{ session.is_fully_locked ? 'All locked' : 'Projects active' }}
          </AppBadge>
        </div>
        <div class="text-muted small">
          <template v-if="session.lifecycle_phase === 'proposal_phase'">
            Initial: {{ formatDate(session.initial_draft_deadline) || '—' }}
            · Final: {{ formatDate(session.final_lock_deadline) || '—' }}
          </template>
          <template v-else-if="session.lifecycle_phase === 'phase_1'">
            Phase 1 start: {{ formatDate(session.phase_1_initial_deadline) || '—' }}
            · Phase 1 lock: {{ formatDate(session.phase_1_final_lock_deadline) || '—' }}
          </template>
          <template v-else-if="session.lifecycle_phase === 'phase_2'">
            Phase 2 start: {{ formatDate(session.phase_2_initial_deadline) || '—' }}
            · Phase 2 lock: {{ formatDate(session.phase_2_final_lock_deadline) || '—' }}
          </template>
          · Projects: {{ session.projects_count ?? 0 }}
          · Students: {{ session.students_count ?? 0 }}
        </div>
        <p v-if="session.initial_deadline_pending" class="text-warning small mb-0 mt-2">
          Initial deadline pending — new registrations will close automatically at {{ formatDate(session.initial_draft_deadline) }}.
        </p>
        <p v-if="session.notes" class="small mt-2 mb-0">{{ session.notes }}</p>
      </div>

      <div
        v-if="authStore.canManageProposalSessions && !session.is_submission_open && initialDeadlineHasPassed && ['proposal_phase', 'phase_1', 'phase_2'].includes(session.lifecycle_phase)"
        class="alert alert-warning py-2 small mb-2"
      >
        The initial draft deadline has already passed, so submissions can't be (re)opened yet.
        Extend it to a future date/time using "Extend Session Deadlines" below, then click Open Submissions.
      </div>

      <div v-if="authStore.canManageProposalSessions" class="d-flex flex-wrap gap-2 mb-4">
        <button
          v-if="['proposal_phase', 'phase_1', 'phase_2'].includes(session.lifecycle_phase) && !session.is_submission_open"
          type="button"
          class="btn btn-success btn-sm"
          :disabled="saving"
          @click="toggleOpen(true)"
        >
          Open Submissions
        </button>
        <button
          v-else-if="['proposal_phase', 'phase_1', 'phase_2'].includes(session.lifecycle_phase) && session.can_manually_close_submissions"
          type="button"
          class="btn btn-warning btn-sm"
          :disabled="saving"
          @click="toggleOpen(false)"
        >
          Close Submissions
        </button>
        <button
          v-if="['proposal_phase', 'phase_1', 'phase_2'].includes(session.lifecycle_phase) && !session.is_fully_locked"
          type="button"
          class="btn btn-danger btn-sm"
          :disabled="saving"
          @click="toggleLock(true)"
        >
          Lock All
        </button>
        <button
          v-else-if="['proposal_phase', 'phase_1', 'phase_2'].includes(session.lifecycle_phase)"
          type="button"
          class="btn btn-outline-success btn-sm"
          :disabled="saving"
          @click="toggleLock(false)"
        >
          Unlock All
        </button>
        <button
          v-if="session.lifecycle_phase === 'proposal_phase'"
          type="button"
          class="btn btn-outline-dark btn-sm"
          :disabled="saving"
          @click="completePhase"
        >
          Complete Proposal Phase
        </button>
        <button
          v-if="session.lifecycle_phase === 'phase_1'"
          type="button"
          class="btn btn-outline-dark btn-sm"
          :disabled="saving"
          @click="completePhase1Action"
        >
          Complete Phase 1
        </button>
        <button
          v-if="session.lifecycle_phase === 'phase_2'"
          type="button"
          class="btn btn-outline-dark btn-sm"
          :disabled="saving"
          @click="completePhase2Action"
        >
          Complete Phase 2
        </button>
        <button
          v-if="['proposal_phase', 'phase_1', 'phase_2'].includes(session.lifecycle_phase)"
          type="button"
          class="btn btn-outline-primary btn-sm"
          :disabled="saving || generatingReports"
          @click="generateReports"
        >
          Generate Repository Reports
        </button>
      </div>

      <AppCard title="Session Repository" class="mb-4">
        <p class="text-muted small mb-3">
          PDF workflow report and Excel student/project summaries for this session.
          Reports are generated automatically when a phase is completed; you can also generate them early using the button above.
        </p>
        <div v-if="reportBatches.length" class="repository-list">
          <div v-for="batch in reportBatches" :key="batch.batch_key" class="repository-batch mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <div>
                <strong>{{ batch.lifecycle_phase_label }}</strong>
                <span class="text-muted small ms-2">Generated {{ formatDate(batch.generated_at) }}</span>
                <span v-if="batch.generated_by" class="text-muted small ms-1">by {{ batch.generated_by }}</span>
              </div>
            </div>
            <div class="list-group list-group-flush">
              <div
                v-for="report in batch.reports"
                :key="report.id"
                class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-2"
              >
                <div>
                  <div>{{ report.report_type_label }}</div>
                  <div class="text-muted small">{{ report.file_name }} · {{ formatFileSize(report.file_size) }}</div>
                </div>
                <button
                  type="button"
                  class="btn btn-outline-primary btn-sm"
                  :disabled="downloadingReportId === report.id"
                  @click="downloadReport(report)"
                >
                  Download
                </button>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-muted small">No repository reports generated yet.</div>
      </AppCard>

      <div class="row g-4">
        <div class="col-12 col-xl-6">
          <AppCard v-if="authStore.canManageProposalSessions" title="Edit Session">
            <form @submit.prevent="saveSession">
              <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
              <div class="row g-3">
                <div class="col-md-8">
                  <label class="form-label">Session Name</label>
                  <input v-model="form.name" type="text" class="form-control" required />
                </div>
                <div class="col-md-4">
                  <label class="form-label">Code</label>
                  <input v-model="form.code" type="text" class="form-control" required />
                </div>
                <div v-if="session.lifecycle_phase === 'proposal_phase'" class="col-md-6">
                  <label class="form-label">Initial Draft Deadline</label>
                  <input v-model="form.initial_draft_deadline" type="datetime-local" class="form-control" />
                </div>
                <div v-if="session.lifecycle_phase === 'proposal_phase'" class="col-md-6">
                  <label class="form-label">Final Lock Deadline</label>
                  <input v-model="form.final_lock_deadline" type="datetime-local" class="form-control" />
                </div>
                <div v-if="session.lifecycle_phase === 'phase_1'" class="col-md-6">
                  <label class="form-label">Phase 1 Start Deadline</label>
                  <input v-model="form.phase_1_initial_deadline" type="datetime-local" class="form-control" />
                </div>
                <div v-if="session.lifecycle_phase === 'phase_1'" class="col-md-6">
                  <label class="form-label">Phase 1 Final Lock</label>
                  <input v-model="form.phase_1_final_lock_deadline" type="datetime-local" class="form-control" />
                </div>
                <div v-if="session.lifecycle_phase === 'phase_2'" class="col-md-6">
                  <label class="form-label">Phase 2 Start Deadline</label>
                  <input v-model="form.phase_2_initial_deadline" type="datetime-local" class="form-control" />
                </div>
                <div v-if="session.lifecycle_phase === 'phase_2'" class="col-md-6">
                  <label class="form-label">Phase 2 Final Lock</label>
                  <input v-model="form.phase_2_final_lock_deadline" type="datetime-local" class="form-control" />
                </div>
                <div class="col-12">
                  <label class="form-label">Notes</label>
                  <textarea v-model="form.notes" class="form-control" rows="2"></textarea>
                </div>
              </div>
              <button type="submit" class="btn btn-primary btn-sm mt-3" :disabled="saving">Update Session</button>
            </form>
          </AppCard>

          <AppCard v-if="authStore.canManageProposalSessions" title="Extend Session Deadlines" class="mt-4">
            <p class="text-muted small">
              Extending deadlines for <strong>{{ session.name }}</strong>
              <AppBadge v-if="session.is_current_proposal_session" variant="primary" class="ms-2">Current Proposal Session</AppBadge>
            </p>
            <div v-if="session.lifecycle_phase === 'proposal_phase' && !session.is_current_proposal_session" class="alert alert-warning py-2 small">
              This is not the current proposal session. Extensions should be applied to the highlighted current session.
            </div>
            <form @submit.prevent="extendDeadlines">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">{{ extendInitialLabel }}</label>
                  <input v-model="extendForm.initial_draft_deadline" type="datetime-local" class="form-control" />
                </div>
                <div class="col-md-6">
                  <label class="form-label">{{ extendFinalLabel }}</label>
                  <input v-model="extendForm.final_lock_deadline" type="datetime-local" class="form-control" />
                </div>
              </div>
              <button type="submit" class="btn btn-warning btn-sm mt-3" :disabled="saving">Extend Deadlines</button>
            </form>
          </AppCard>

          <AppCard v-if="authStore.canGrantProposalSessionExtensions" title="Deadline Extension" class="mt-4">
            <form @submit.prevent="grantExtension">
              <div class="row g-3">
                <div class="col-12">
                  <SessionStudentPicker
                    v-model="extensionForm.user_id"
                    :options="extensionStudents"
                    :disabled="extensionStudentsLoading"
                    label="Student or Project"
                    hint="Search by student name or project title. If the student is on a team, the extension is granted to the whole team automatically."
                    required
                  />
                  <div v-if="extensionStudentsLoading" class="text-muted small mt-1">Loading students...</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Extended Initial Deadline</label>
                  <input v-model="extensionForm.extended_initial_deadline" type="datetime-local" class="form-control" />
                </div>
                <div class="col-md-6">
                  <label class="form-label">Extended Final Deadline</label>
                  <input v-model="extensionForm.extended_final_deadline" type="datetime-local" class="form-control" />
                </div>
                <div class="col-12">
                  <label class="form-label">Reason</label>
                  <textarea v-model="extensionForm.reason" class="form-control" rows="2"></textarea>
                </div>
              </div>
              <button
                type="submit"
                class="btn btn-outline-primary btn-sm mt-3"
                :disabled="saving || !extensionForm.user_id"
              >
                Grant Extension
              </button>
            </form>

            <div v-if="extensions.length" class="mt-4">
              <h6 class="small text-uppercase text-muted mb-2">Granted Extensions</h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Project / Student</th>
                      <th>Initial</th>
                      <th>Final</th>
                      <th>Reason</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="extension in extensions" :key="extension.id">
                      <td>
                        <div v-if="extension.project_title" class="fw-semibold">{{ extension.project_title }}</div>
                        <div class="text-muted small">
                          {{ extension.students.map((s) => s.name).join(', ') }}
                        </div>
                      </td>
                      <td class="small">{{ formatDate(extension.extended_initial_deadline) || '—' }}</td>
                      <td class="small">{{ formatDate(extension.extended_final_deadline) || '—' }}</td>
                      <td class="small">{{ extension.reason || '—' }}</td>
                      <td class="text-end">
                        <button
                          type="button"
                          class="btn btn-outline-danger btn-sm"
                          :disabled="saving"
                          @click="revokeExtension(extension)"
                        >
                          Revoke
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div v-else class="text-muted small mt-3">No extensions granted yet.</div>
          </AppCard>
        </div>

        <div class="col-12 col-xl-6">
          <AppCard title="Session Students">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
              <p class="text-muted small mb-0">
                Students for session code <strong>{{ session.code }}</strong>.
                <span v-if="!session.is_submission_open" class="d-block mt-1">
                  Open the session before uploading the student list.
                </span>
              </p>
              <div v-if="authStore.canManageProposalSessions" class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="downloadStudentsTemplate">
                  <i class="bi bi-download me-1"></i> Template
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="exportStudentsList">
                  <i class="bi bi-filetype-csv me-1"></i> Export CSV
                </button>
                <button
                  type="button"
                  class="btn btn-outline-primary btn-sm"
                  :disabled="!session.is_submission_open || importingStudents"
                  @click="openStudentImport"
                >
                  <i class="bi bi-upload me-1"></i>
                  {{ importingStudents ? 'Importing...' : 'Import Excel' }}
                </button>
              </div>
            </div>

            <input
              ref="studentImportInput"
              type="file"
              class="d-none"
              accept=".xlsx,.xls,.csv"
              @change="handleStudentImportFile"
            />

            <div v-if="sessionStudentsLoading" class="text-muted small">Loading students...</div>
            <ul v-else-if="sessionStudents.length" class="list-group list-group-flush small">
              <li v-for="student in sessionStudents" :key="student.id" class="list-group-item px-0">
                {{ student.name }}
                <span class="text-muted">· ID {{ student.id }} · {{ student.registration_no || student.email }}</span>
              </li>
            </ul>
            <div v-else class="text-muted small">No students match this session code in the selected program.</div>
          </AppCard>
        </div>
      </div>
    </template>
  </div>

  <div v-if="showStudentImportResult" class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.45)">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">Student Import Results</h5>
          <button type="button" class="btn-close" @click="closeStudentImportResult"></button>
        </div>
        <div class="modal-body">
          <div v-if="studentImportResultMessage" class="alert alert-info py-2">{{ studentImportResultMessage }}</div>
          <p class="small text-muted mb-3">
            Passwords are taken from the Password column in your Excel file.
          </p>

          <div v-if="studentImportCreated.length" class="mb-4">
            <h6 class="mb-2">Created ({{ studentImportCreated.length }})</h6>
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
                  <tr v-for="item in studentImportCreated" :key="`created-${item.email}`">
                    <td>{{ item.sap_id }}</td>
                    <td>{{ item.name }}</td>
                    <td>{{ item.email }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-if="studentImportUpdated.length" class="mb-4">
            <h6 class="mb-2">Updated ({{ studentImportUpdated.length }})</h6>
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
                  <tr v-for="item in studentImportUpdated" :key="`updated-${item.email}`">
                    <td>{{ item.sap_id }}</td>
                    <td>{{ item.name }}</td>
                    <td>{{ item.email }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-if="studentImportSkipped.length" class="mb-4">
            <h6 class="mb-2 text-muted">Skipped — already past proposal phase ({{ studentImportSkipped.length }})</h6>
            <p class="small text-muted mb-2">
              These students already have an approved proposal and have moved on, so their record was left unchanged.
            </p>
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
                  <tr v-for="item in studentImportSkipped" :key="`skipped-${item.email}`">
                    <td>{{ item.sap_id }}</td>
                    <td>{{ item.name }}</td>
                    <td>{{ item.email }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div v-if="studentImportErrors.length">
            <h6 class="mb-2 text-danger">Errors ({{ studentImportErrors.length }})</h6>
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
                  <tr v-for="item in studentImportErrors" :key="`${item.row}-${item.email || item.message}`">
                    <td>{{ item.row }}</td>
                    <td>{{ item.sap_id || '—' }}</td>
                    <td>{{ item.name || '—' }}</td>
                    <td>{{ item.email || '—' }}</td>
                    <td>{{ item.message }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" @click="closeStudentImportResult">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import SessionStudentPicker from '@/components/admin/SessionStudentPicker.vue'
import {
  closeProposalSession,
  previewCompleteProposalPhase,
  completeProposalPhase,
  completePhase1,
  completePhase2,
  downloadProposalSessionReport,
  downloadProposalSessionStudentsTemplate,
  exportProposalSessionStudents,
  extendProposalSessionDeadlines,
  fetchProposalSession,
  fetchProposalSessionStudents,
  generateProposalSessionReports,
  grantProposalSessionExtension,
  importProposalSessionStudents,
  lockProposalSession,
  openProposalSession,
  revokeProposalSessionExtension,
  unlockProposalSession,
  updateProposalSession,
} from '@/api/proposalSessions'
import { formatApiError } from '@/utils/apiErrors'
import { useAuthStore } from '@/stores/auth'
import { confirmDialog } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'

const route = useRoute()
const authStore = useAuthStore()

const session = ref(null)
const extensions = ref([])
const reports = ref([])
const sessionStudents = ref([])
const loading = ref(true)
const saving = ref(false)
const generatingReports = ref(false)
const downloadingReportId = ref(null)
const loadError = ref('')
const formError = ref('')
const sessionStudentsLoading = ref(false)
const extensionStudents = ref([])
const extensionStudentsLoading = ref(false)
const studentImportInput = ref(null)
const importingStudents = ref(false)
const showStudentImportResult = ref(false)
const studentImportResultMessage = ref('')
const studentImportCreated = ref([])
const studentImportUpdated = ref([])
const studentImportSkipped = ref([])
const studentImportErrors = ref([])

const form = reactive({
  name: '',
  code: '',
  initial_draft_deadline: '',
  final_lock_deadline: '',
  phase_1_initial_deadline: '',
  phase_1_final_lock_deadline: '',
  phase_2_initial_deadline: '',
  phase_2_final_lock_deadline: '',
  notes: '',
})

const extendForm = reactive({
  initial_draft_deadline: '',
  final_lock_deadline: '',
})

const extensionForm = reactive({
  user_id: null,
  extended_initial_deadline: '',
  extended_final_deadline: '',
  reason: '',
})

const extendInitialLabel = computed(() => {
  if (session.value?.lifecycle_phase === 'phase_1') return 'Phase 1 Start Deadline'
  if (session.value?.lifecycle_phase === 'phase_2') return 'Phase 2 Start Deadline'
  return 'Initial Draft Deadline'
})

const extendFinalLabel = computed(() => {
  if (session.value?.lifecycle_phase === 'phase_1') return 'Phase 1 Final Lock'
  if (session.value?.lifecycle_phase === 'phase_2') return 'Phase 2 Final Lock'
  return 'Final Lock Deadline'
})

const toLocalInput = (value) => {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '')

const formatFileSize = (bytes) => {
  const size = Number(bytes || 0)
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const lifecycleInitialDeadline = computed(() => {
  if (!session.value) return null
  if (session.value.lifecycle_phase === 'phase_1') return session.value.phase_1_initial_deadline
  if (session.value.lifecycle_phase === 'phase_2') return session.value.phase_2_initial_deadline
  return session.value.initial_draft_deadline
})

const initialDeadlineHasPassed = computed(() => {
  const deadline = lifecycleInitialDeadline.value
  if (!deadline) return false
  return new Date(deadline).getTime() <= Date.now()
})

const reportBatches = computed(() => {
  const grouped = new Map()
  for (const report of reports.value) {
    const existing = grouped.get(report.batch_key)
    if (existing) {
      existing.reports.push(report)
      continue
    }
    grouped.set(report.batch_key, {
      batch_key: report.batch_key,
      lifecycle_phase_label: report.lifecycle_phase_label,
      generated_at: report.generated_at,
      generated_by: report.generated_by,
      reports: [report],
    })
  }

  return Array.from(grouped.values()).sort(
    (a, b) => new Date(b.generated_at).getTime() - new Date(a.generated_at).getTime()
  )
})

let refreshTimer = null

const clearAutoRefresh = () => {
  if (refreshTimer) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
}

const scheduleAutoRefresh = () => {
  clearAutoRefresh()
  if (!session.value?.initial_deadline_pending) return
  refreshTimer = setInterval(() => {
    loadSession({ silent: true })
  }, 30000)
}

const lifecycleExtendDeadlines = (current) => {
  if (current.lifecycle_phase === 'phase_1') {
    return {
      initial: current.phase_1_initial_deadline,
      final: current.phase_1_final_lock_deadline,
    }
  }
  if (current.lifecycle_phase === 'phase_2') {
    return {
      initial: current.phase_2_initial_deadline,
      final: current.phase_2_final_lock_deadline,
    }
  }
  return {
    initial: current.initial_draft_deadline,
    final: current.final_lock_deadline,
  }
}

const syncForms = (current) => {
  form.name = current.name
  form.code = current.code
  form.initial_draft_deadline = toLocalInput(current.initial_draft_deadline)
  form.final_lock_deadline = toLocalInput(current.final_lock_deadline)
  form.phase_1_initial_deadline = toLocalInput(current.phase_1_initial_deadline)
  form.phase_1_final_lock_deadline = toLocalInput(current.phase_1_final_lock_deadline)
  form.phase_2_initial_deadline = toLocalInput(current.phase_2_initial_deadline)
  form.phase_2_final_lock_deadline = toLocalInput(current.phase_2_final_lock_deadline)
  form.notes = current.notes || ''

  const deadlines = lifecycleExtendDeadlines(current)
  extendForm.initial_draft_deadline = toLocalInput(deadlines.initial)
  extendForm.final_lock_deadline = toLocalInput(deadlines.final)
}

const loadExtensionStudents = async () => {
  extensionForm.user_id = null
  extensionStudents.value = []
  if (!session.value?.id) return

  extensionStudentsLoading.value = true
  try {
    const res = await fetchProposalSessionStudents(session.value.id, { per_page: 500 })
    extensionStudents.value = res.data?.students || []
  } catch {
    extensionStudents.value = []
  } finally {
    extensionStudentsLoading.value = false
  }
}

const loadSessionStudents = async () => {
  if (!session.value?.id) return
  sessionStudentsLoading.value = true
  try {
    const res = await fetchProposalSessionStudents(session.value.id, { per_page: 100 })
    sessionStudents.value = res.data?.students || []
  } catch {
    sessionStudents.value = []
  } finally {
    sessionStudentsLoading.value = false
  }
}

const loadSession = async ({ silent = false } = {}) => {
  if (!silent) {
    loading.value = true
  }
  loadError.value = ''
  try {
    const res = await fetchProposalSession(route.params.id)
    session.value = res.data?.session || null
    extensions.value = res.data?.extensions || []
    reports.value = res.data?.reports || []
    if (!session.value) {
      loadError.value = 'Session not found.'
      return
    }
    syncForms(session.value)
    scheduleAutoRefresh()
    await Promise.all([
      loadSessionStudents(),
      authStore.canGrantProposalSessionExtensions ? loadExtensionStudents() : Promise.resolve(),
    ])
  } catch (err) {
    if (!silent) {
      loadError.value = formatApiError(err, 'Failed to load session.')
    }
  } finally {
    if (!silent) {
      loading.value = false
    }
  }
}

const saveSession = async () => {
  formError.value = ''
  saving.value = true
  try {
    const payload = {
      name: form.name.trim(),
      code: form.code.trim().toUpperCase(),
      notes: form.notes || null,
    }
    if (session.value.lifecycle_phase === 'proposal_phase') {
      payload.initial_draft_deadline = form.initial_draft_deadline || null
      payload.final_lock_deadline = form.final_lock_deadline || null
    } else if (session.value.lifecycle_phase === 'phase_1') {
      payload.phase_1_initial_deadline = form.phase_1_initial_deadline || null
      payload.phase_1_final_lock_deadline = form.phase_1_final_lock_deadline || null
    } else if (session.value.lifecycle_phase === 'phase_2') {
      payload.phase_2_initial_deadline = form.phase_2_initial_deadline || null
      payload.phase_2_final_lock_deadline = form.phase_2_final_lock_deadline || null
    }
    await updateProposalSession(session.value.id, payload)
    await loadSession()
  } catch (err) {
    formError.value = formatApiError(err, 'Failed to update session.')
  } finally {
    saving.value = false
  }
}

const toggleOpen = async (open) => {
  saving.value = true
  try {
    if (open) await openProposalSession(session.value.id)
    else await closeProposalSession(session.value.id)
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to update submission status.')
  } finally {
    saving.value = false
  }
}

const toggleLock = async (lock) => {
  saving.value = true
  try {
    if (lock) await lockProposalSession(session.value.id)
    else await unlockProposalSession(session.value.id)
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to update lock status.')
  } finally {
    saving.value = false
  }
}

const generateReports = async () => {
  generatingReports.value = true
  loadError.value = ''
  try {
    const res = await generateProposalSessionReports(session.value.id)
    reports.value = res.data?.reports || reports.value
    toast.success('Session repository reports generated successfully.')
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to generate session reports.')
  } finally {
    generatingReports.value = false
  }
}

const downloadReport = async (report) => {
  downloadingReportId.value = report.id
  try {
    await downloadProposalSessionReport(session.value.id, report.id, report.file_name)
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to download report.')
  } finally {
    downloadingReportId.value = null
  }
}

const completePhase = async () => {
  let message = `Complete proposal phase for ${session.value.name}? This will move the session to Phase 1.`

  try {
    const previewRes = await previewCompleteProposalPhase(session.value.id)
    const preview = previewRes.data
    message += ` ${preview.approved_count} approved project(s) will advance to Phase 1.`
    if (preview.pending_projects_count > 0 || preview.unplaced_students_count > 0) {
      message += ` ${preview.pending_projects_count} pending project(s) and ${preview.unplaced_students_count} unplaced student(s) are not yet approved — nothing is deleted; they will automatically carry forward into the next proposal session for this program.`
    }
  } catch {
    // If the preview fails for any reason, fall back to the generic message below
    // rather than blocking the action entirely.
  }

  const confirmed = await confirmDialog.confirm({
    title: 'Complete proposal phase',
    message,
    confirmText: 'Complete phase',
    variant: 'danger',
  })
  if (!confirmed) return
  saving.value = true
  try {
    const res = await completeProposalPhase(session.value.id)
    const summary = res.data?.summary
    if (res.data?.reports?.length) {
      reports.value = res.data.reports
    }
    if (summary) {
      toast.success(
        `Proposal phase completed. Approved kept: ${summary.approved_projects_kept}, pending carried forward: ${summary.pending_projects_kept}, unplaced students carried forward: ${summary.unplaced_students_kept}`
      )
    } else {
      toast.success('Proposal phase completed.')
    }
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to complete proposal phase.')
  } finally {
    saving.value = false
  }
}

const completePhase1Action = async () => {
  const confirmed = await confirmDialog.confirm({
    title: 'Complete Phase 1',
    message: `Complete Phase 1 for ${session.value.name}? Approved Phase 1 projects will move to Phase 2. Pending Phase 1 projects will remain pending for a future session.`,
    confirmText: 'Complete Phase 1',
    variant: 'warning',
  })
  if (!confirmed) return
  saving.value = true
  try {
    const res = await completePhase1(session.value.id)
    const summary = res.data?.summary
    if (res.data?.reports?.length) {
      reports.value = res.data.reports
    }
    if (summary) {
      toast.success(
        `Phase 1 completed. Advanced to Phase 2: ${summary.phase_1_approved_advanced}, pending kept: ${summary.phase_1_pending_kept}`
      )
    } else {
      toast.success('Phase 1 completed.')
    }
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to complete Phase 1.')
  } finally {
    saving.value = false
  }
}

const completePhase2Action = async () => {
  const confirmed = await confirmDialog.confirm({
    title: 'Complete Phase 2',
    message: `Complete Phase 2 for ${session.value.name}? Approved Phase 2 projects will be marked completed. Pending Phase 2 projects will remain pending for a future session.`,
    confirmText: 'Complete Phase 2',
    variant: 'warning',
  })
  if (!confirmed) return
  saving.value = true
  try {
    const res = await completePhase2(session.value.id)
    const summary = res.data?.summary
    if (res.data?.reports?.length) {
      reports.value = res.data.reports
    }
    if (summary) {
      toast.success(
        `Phase 2 completed. Projects completed: ${summary.phase_2_approved_completed}, pending kept: ${summary.phase_2_pending_kept}`
      )
    } else {
      toast.success('Phase 2 completed.')
    }
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to complete Phase 2.')
  } finally {
    saving.value = false
  }
}

const extendDeadlines = async () => {
  saving.value = true
  try {
    await extendProposalSessionDeadlines(session.value.id, {
      initial_draft_deadline: extendForm.initial_draft_deadline || null,
      final_lock_deadline: extendForm.final_lock_deadline || null,
    })
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to extend deadlines.')
  } finally {
    saving.value = false
  }
}

const grantExtension = async () => {
  if (!extensionForm.user_id) return
  saving.value = true
  try {
    await grantProposalSessionExtension(session.value.id, {
      user_id: extensionForm.user_id,
      extended_initial_deadline: extensionForm.extended_initial_deadline || null,
      extended_final_deadline: extensionForm.extended_final_deadline || null,
      reason: extensionForm.reason || null,
    })
    extensionForm.user_id = null
    extensionForm.extended_initial_deadline = ''
    extensionForm.extended_final_deadline = ''
    extensionForm.reason = ''
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to grant extension.')
  } finally {
    saving.value = false
  }
}

const revokeExtension = async (extension) => {
  if (!extension.user_id) return
  const who = extension.project_title
    ? `the team on "${extension.project_title}" (${extension.students.map((s) => s.name).join(', ')})`
    : extension.students.map((s) => s.name).join(', ')
  const confirmed = await confirmDialog.confirm({
    title: 'Revoke extension',
    message: `Revoke extension for ${who}?`,
    confirmText: 'Revoke',
    variant: 'danger',
  })
  if (!confirmed) return
  saving.value = true
  try {
    await revokeProposalSessionExtension(session.value.id, extension.user_id)
    toast.success('Extension revoked.')
    await loadSession()
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to revoke extension.')
  } finally {
    saving.value = false
  }
}

const downloadStudentsTemplate = async () => {
  if (!session.value?.id) return
  try {
    await downloadProposalSessionStudentsTemplate(session.value.id, session.value.code)
  } catch (err) {
    loadError.value = formatApiError(err, 'Could not download template.')
  }
}

const exportStudentsList = async () => {
  if (!session.value?.id) return
  try {
    await exportProposalSessionStudents(session.value.id, session.value.code)
    toast.success('Session students exported.')
  } catch (err) {
    toast.error(formatApiError(err, 'Could not export students.'))
  }
}

const openStudentImport = () => {
  if (!session.value?.is_submission_open) {
    toast.warning('Open the session before uploading the student list.')
    return
  }
  studentImportInput.value?.click()
}

const handleStudentImportFile = async (event) => {
  const file = event.target.files?.[0]
  event.target.value = ''

  if (!file || !session.value?.id) {
    return
  }

  importingStudents.value = true
  try {
    const response = await importProposalSessionStudents(session.value.id, file)
    studentImportResultMessage.value = response.message || 'Import completed.'
    studentImportCreated.value = response.data?.created || []
    studentImportUpdated.value = response.data?.updated || []
    studentImportSkipped.value = response.data?.skipped || []
    studentImportErrors.value = response.data?.errors || []
    showStudentImportResult.value = true
    await Promise.all([
      loadSessionStudents(),
      authStore.canGrantProposalSessionExtensions ? loadExtensionStudents() : Promise.resolve(),
    ])
  } catch (err) {
    loadError.value = formatApiError(err, 'Student import failed.')
  } finally {
    importingStudents.value = false
  }
}

const closeStudentImportResult = () => {
  showStudentImportResult.value = false
  studentImportResultMessage.value = ''
  studentImportCreated.value = []
  studentImportUpdated.value = []
  studentImportSkipped.value = []
  studentImportErrors.value = []
}

watch(
  () => route.params.id,
  () => {
    loadSession()
  }
)

onMounted(() => {
  loadSession()
})

onUnmounted(clearAutoRefresh)
</script>

<style scoped>
.session-overview {
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  padding: 1rem 1.25rem;
  background: #f8fafc;
}

.repository-batch {
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  padding: 0.75rem 1rem;
  background: #fff;
}
</style>
