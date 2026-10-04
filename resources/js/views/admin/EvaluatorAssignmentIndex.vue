<template>
  <div>
    <PageHeader
      title="Evaluator Assignment"
      subtitle="Auto-assign evaluators to all projects in a session/phase, or review what's still pending"
      breadcrumb="Evaluator Assignment"
    />

    <AppCard title="Select Session &amp; Phase" class="mb-3">
      <div class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Proposal Session</label>
          <select v-model="filters.session_id" class="form-select" @change="onFiltersChanged">
            <option :value="null" disabled>Select a session…</option>
            <option v-for="session in sessions" :key="session.id" :value="session.id">
              {{ session.name }} ({{ session.code }})
            </option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Phase</label>
          <select v-model="filters.phase" class="form-select" @change="onFiltersChanged">
            <option value="proposal">Proposal</option>
            <option value="phase_1">Phase-1</option>
            <option value="phase_2">Phase-2</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Evaluators per Project</label>
          <input v-model.number="filters.evaluators_per_project" type="number" min="1" max="10" class="form-control" @change="onFiltersChanged" />
        </div>
        <div class="col-md-2">
          <button type="button" class="btn btn-outline-primary btn-sm w-100" :disabled="!filters.session_id || loadingPreview" @click="loadPreview">
            Preview
          </button>
        </div>
      </div>
    </AppCard>

    <div v-if="canManageLimits" class="alert alert-light border d-flex flex-wrap align-items-center gap-3 mb-3">
      <div>
        <strong>Default evaluator limit for {{ phaseLabel }}:</strong>
        <span class="ms-1">{{ defaultLimits[filters.phase] }}</span> project(s) per evaluator.
      </div>
      <div class="d-flex align-items-center gap-2">
        <input v-model.number="limitEditValue" type="number" min="0" max="100" class="form-control form-control-sm" style="width: 90px" />
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="savingLimit" @click="saveDefaultLimit">
          Update Limit
        </button>
      </div>
    </div>

    <div v-if="preview" class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Candidate Projects</div>
          <div class="summary-card__value">{{ preview.total_candidates }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Available Evaluators</div>
          <div class="summary-card__value">{{ preview.available_evaluators }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Would Assign</div>
          <div class="summary-card__value text-success">{{ preview.assigned_count }}</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="summary-card">
          <div class="summary-card__label">Would Be Skipped</div>
          <div class="summary-card__value" :class="preview.skipped_count > 0 ? 'text-danger' : ''">{{ preview.skipped_count }}</div>
        </div>
      </div>
    </div>

    <div v-if="preview && preview.skipped_count > 0" class="alert alert-warning mb-3">
      <strong>Not enough evaluator capacity</strong> for {{ preview.skipped_count }} project(s) — they will stay
      pending. Increase the default evaluator limit above, add more evaluators, or lower "Evaluators per Project"
      before assigning.
      <ul class="mb-0 mt-2 small">
        <li v-for="project in preview.skipped_projects" :key="project.id">
          {{ project.title }} <span class="text-muted">({{ project.leader_name }})</span>
        </li>
      </ul>
    </div>

    <div v-if="preview" class="mb-4">
      <button type="button" class="btn btn-primary" :disabled="assigning || preview.total_candidates === 0" @click="runAssign">
        Auto Assign Evaluators
      </button>
      <span class="text-muted small ms-2">
        Will assign {{ filters.evaluators_per_project }} evaluator(s) to each of the {{ preview.total_candidates }} candidate project(s).
      </span>
    </div>

    <AppCard title="Pending Projects" subtitle="Projects still needing evaluator assignment for this phase">
      <template #header>
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!pendingProjects.length" @click="copyEmails">
          <i class="bi bi-clipboard me-1"></i> Copy Emails
        </button>
      </template>

      <div v-if="loadingPending" class="text-center py-4">
        <div class="spinner-border spinner-border-sm text-primary"></div>
      </div>

      <EmptyState
        v-else-if="!pendingProjects.length"
        title="Nothing pending"
        description="Every project in this scope already has evaluators assigned."
        icon="bi bi-check2-circle"
      />

      <div v-else class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>Project</th>
              <th>Leader</th>
              <th>Supervisor</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="project in pendingProjects" :key="project.id">
              <td>
                <router-link :to="`/projects/${project.id}`">{{ project.title }}</router-link>
              </td>
              <td>
                <div>{{ project.leader_name || '—' }}</div>
                <div class="text-muted small">{{ project.leader_email }}</div>
              </td>
              <td>
                <div>{{ project.supervisor_name || '—' }}</div>
                <div class="text-muted small">{{ project.supervisor_email || '—' }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </AppCard>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  fetchEvaluatorAssignmentSessions,
  fetchPendingEvaluatorAssignments,
  previewAutoEvaluatorAssignment,
  runAutoEvaluatorAssignment,
} from '@/api/evaluatorAssignment'
import { fetchProposalSettings, updateProposalSettings } from '@/api/proposals'
import { useAuthStore } from '@/stores/auth'
import { toast } from '@/composables/useToast'
import { formatApiError } from '@/utils/apiErrors'

const authStore = useAuthStore()
const canManageLimits = computed(() => authStore.canManageProposalSettings)

const sessions = ref([])
const preview = ref(null)
const pendingProjects = ref([])
const loadingPreview = ref(false)
const loadingPending = ref(false)
const assigning = ref(false)
const savingLimit = ref(false)
const defaultLimits = ref({ proposal: 5, phase_1: 5, phase_2: 5 })
const limitEditValue = ref(5)

const filters = reactive({
  session_id: null,
  phase: 'proposal',
  evaluators_per_project: 2,
})

const phaseLabel = computed(() => ({ proposal: 'Proposal', phase_1: 'Phase-1', phase_2: 'Phase-2' }[filters.phase]))

const loadSessions = async () => {
  const res = await fetchEvaluatorAssignmentSessions()
  sessions.value = res.data || []
  if (!filters.session_id && sessions.value.length) {
    filters.session_id = sessions.value[0].id
  }
}

const loadSettings = async () => {
  const res = await fetchProposalSettings()
  filters.evaluators_per_project = res.data.min_evaluators || 2
  defaultLimits.value = res.data.default_evaluator_limits || defaultLimits.value
  limitEditValue.value = defaultLimits.value[filters.phase]
}

const loadPreview = async () => {
  if (!filters.session_id) return

  loadingPreview.value = true
  try {
    const res = await previewAutoEvaluatorAssignment({
      proposal_session_id: filters.session_id,
      phase: filters.phase,
      evaluators_per_project: filters.evaluators_per_project,
    })
    preview.value = res.data
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to load preview.'))
  } finally {
    loadingPreview.value = false
  }
}

const loadPending = async () => {
  loadingPending.value = true
  try {
    const res = await fetchPendingEvaluatorAssignments({
      phase: filters.phase,
      proposal_session_id: filters.session_id || undefined,
    })
    pendingProjects.value = res.data.projects || []
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to load pending projects.'))
  } finally {
    loadingPending.value = false
  }
}

const onFiltersChanged = () => {
  preview.value = null
  limitEditValue.value = defaultLimits.value[filters.phase]
  loadPending()
}

const runAssign = async () => {
  if (!filters.session_id) return

  assigning.value = true
  try {
    const res = await runAutoEvaluatorAssignment({
      proposal_session_id: filters.session_id,
      phase: filters.phase,
      evaluators_per_project: filters.evaluators_per_project,
    })
    toast.success(`${res.data.assigned_count} project(s) assigned evaluators.${res.data.skipped_count ? ` ${res.data.skipped_count} still pending.` : ''}`)
    preview.value = res.data
    await loadPending()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to auto-assign evaluators.'))
  } finally {
    assigning.value = false
  }
}

const saveDefaultLimit = async () => {
  savingLimit.value = true
  try {
    const settingsRes = await fetchProposalSettings()
    const data = settingsRes.data
    const payload = {
      min_members: data.team_limits?.min_members ?? data.min_members,
      max_members: data.team_limits?.max_members ?? data.max_members,
      min_evaluators: data.team_limits?.min_evaluators ?? data.min_evaluators,
      max_evaluators: data.team_limits?.max_evaluators ?? data.max_evaluators,
      supervisor_limits: data.default_supervisor_limits,
      evaluator_limits: { ...data.default_evaluator_limits, [filters.phase]: limitEditValue.value },
      evaluator_visibility: data.evaluator_visibility,
    }
    const res = await updateProposalSettings(payload)
    defaultLimits.value = res.data.default_evaluator_limits
    toast.success('Default evaluator limit updated.')
    await loadPreview()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to update limit.'))
  } finally {
    savingLimit.value = false
  }
}

const copyEmails = async () => {
  const emails = new Set()
  pendingProjects.value.forEach((project) => {
    if (project.leader_email) emails.add(project.leader_email)
    if (project.supervisor_email) emails.add(project.supervisor_email)
  })

  const text = Array.from(emails).join(', ')

  try {
    await navigator.clipboard.writeText(text)
    toast.success(`Copied ${emails.size} email address(es) to clipboard.`)
  } catch {
    toast.error('Could not copy to clipboard. Please copy manually.')
  }
}

onMounted(async () => {
  await Promise.all([loadSessions(), loadSettings()])
  await loadPending()
})
</script>

<style scoped>
.summary-card {
  background: #fff;
  border: 1px solid #ebe9f1;
  border-radius: 0.428rem;
  padding: 1rem 1.15rem;
}

.summary-card__label {
  color: #6e6b7b;
  font-size: 0.82rem;
  margin-bottom: 0.25rem;
}

.summary-card__value {
  color: #5e5873;
  font-size: 1.35rem;
  font-weight: 700;
}
</style>
