<template>
  <div>
    <PageHeader
      title="Proposal Sessions"
      subtitle="Create academic sessions and open a session to manage deadlines, extensions, and lifecycle"
      breadcrumb="Proposal Sessions"
    />

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div class="alert alert-light border mb-4">
      <strong>Session rules</strong>
      <ul class="mb-0 small mt-2">
        <li>Each program has one <strong>current proposal session</strong> in <em>Proposal Phase</em> (students submit proposals).</li>
        <li>Click a session to open its detail page for extensions, deadlines, students, and lifecycle actions.</li>
        <li>When proposal phase ends, use <strong>Complete Proposal Phase</strong> to move the session to Phase 1.</li>
        <li><strong>Initial draft deadline</strong> stops <em>new</em> project registration only. Existing groups can keep working (invites, edits, workflow).</li>
        <li><strong>Final lock deadline</strong> or <strong>Lock All</strong> blocks all further proposal changes for existing projects.</li>
        <li>While the initial draft deadline is still pending, new registrations cannot be closed manually — they close automatically when that deadline passes.</li>
        <li>Students with an individual extension can still submit after session submissions close, until their extended initial deadline.</li>
      </ul>
    </div>

    <div class="row g-4">
      <div v-if="authStore.canManageProposalSessions" class="col-12 col-xl-5">
        <AppCard title="Create Session">
          <form @submit.prevent="saveSession">
            <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">Program</label>
                <select v-model="form.program_id" class="form-select" required>
                  <option value="">Select program</option>
                  <option v-for="program in programs" :key="program.id" :value="program.id">
                    {{ program.name }}
                  </option>
                </select>
              </div>
              <div class="col-md-8">
                <label class="form-label">Session Name</label>
                <input v-model="form.name" type="text" class="form-control" placeholder="Fall 2026" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Code</label>
                <input v-model="form.code" type="text" class="form-control" placeholder="FA26" required />
                <small class="text-muted">Must match student session field (e.g. SP26)</small>
              </div>
              <div class="col-md-6">
                <label class="form-label">Initial Draft Deadline</label>
                <input v-model="form.initial_draft_deadline" type="datetime-local" class="form-control" />
                <div class="form-text">After this time, students cannot register a new proposal. Existing projects continue.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Final Lock Deadline</label>
                <input v-model="form.final_lock_deadline" type="datetime-local" class="form-control" />
                <div class="form-text">After this time (or Lock All), no further proposal changes are allowed.</div>
              </div>
              <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea v-model="form.notes" class="form-control" rows="2"></textarea>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3" :disabled="saving">
              Create Session
            </button>
          </form>
        </AppCard>
      </div>

      <div :class="authStore.canManageProposalSessions ? 'col-12 col-xl-7' : 'col-12'">
        <AppCard title="Sessions">
          <div v-if="loading" class="text-center py-4">
            <div class="spinner-border text-primary"></div>
          </div>
          <div v-else-if="!sessions.length" class="text-muted">No proposal sessions yet.</div>
          <div v-else class="session-list">
            <div
              v-for="session in sessions"
              :key="session.id"
              class="session-item"
              :class="{ 'session-item--current': session.is_current_proposal_session }"
              role="button"
              tabindex="0"
              @click="openSession(session)"
              @keydown.enter="openSession(session)"
            >
              <div class="session-item__header">
                <div>
                  <div class="session-item__title">
                    {{ session.name }} <span class="text-muted">({{ session.code }})</span>
                    <AppBadge v-if="session.is_current_proposal_session" variant="primary" class="ms-1">Current</AppBadge>
                  </div>
                  <div class="text-muted small">{{ session.program_name }}</div>
                </div>
                <div class="d-flex flex-wrap gap-1">
                  <AppBadge variant="info">{{ session.lifecycle_phase_label || 'Proposal Phase' }}</AppBadge>
                  <AppBadge :variant="session.is_submission_open ? 'success' : 'secondary'">
                    {{ session.is_submission_open ? 'New reg. open' : 'New reg. closed' }}
                  </AppBadge>
                  <AppBadge :variant="session.is_fully_locked ? 'danger' : 'secondary'">
                    {{ session.is_fully_locked ? 'All locked' : 'Projects active' }}
                  </AppBadge>
                </div>
              </div>

              <div class="session-item__meta small text-muted">
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
                <span v-if="session.initial_deadline_pending" class="text-warning d-block mt-1">
                  Initial deadline pending — new registrations will close automatically at {{ formatDate(session.initial_draft_deadline) }}.
                </span>
              </div>

              <div class="session-item__actions">
                <button type="button" class="btn btn-outline-primary btn-sm" @click.stop="openSession(session)">
                  Manage
                </button>
              </div>
            </div>
          </div>
        </AppCard>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import { fetchAccessiblePrograms } from '@/api/programs'
import {
  createProposalSession,
  fetchProposalSessions,
} from '@/api/proposalSessions'
import { formatApiError } from '@/utils/apiErrors'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()
const sessions = ref([])
const programs = ref([])
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const formError = ref('')

const form = reactive({
  program_id: '',
  name: '',
  code: '',
  initial_draft_deadline: '',
  final_lock_deadline: '',
  notes: '',
})

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '')

let refreshTimer = null

const clearAutoRefresh = () => {
  if (refreshTimer) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
}

const scheduleAutoRefresh = () => {
  clearAutoRefresh()
  const pending = sessions.value.some((session) => session.initial_deadline_pending)
  if (!pending) return

  refreshTimer = setInterval(() => {
    loadData({ silent: true })
  }, 30000)
}

const openSession = (session) => {
  router.push({ name: 'admin-proposal-session-detail', params: { id: session.id } })
}

const loadData = async ({ silent = false } = {}) => {
  if (!silent) {
    loading.value = true
  }
  loadError.value = ''
  try {
    const [sessionRes, programRes] = await Promise.all([
      fetchProposalSessions(),
      authStore.canManageProposalSessions ? fetchAccessiblePrograms() : Promise.resolve({ data: { programs: [] } }),
    ])
    sessions.value = Array.isArray(sessionRes.data) ? sessionRes.data : (sessionRes.data?.sessions || [])
    programs.value = programRes.data?.programs || []
    if (!form.program_id && programs.value.length) {
      form.program_id = programs.value[0].id
    }
    scheduleAutoRefresh()
  } catch (err) {
    if (!silent) {
      loadError.value = formatApiError(err, 'Failed to load proposal sessions.')
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
    await createProposalSession({
      program_id: Number(form.program_id),
      name: form.name.trim(),
      code: form.code.trim().toUpperCase(),
      initial_draft_deadline: form.initial_draft_deadline || null,
      final_lock_deadline: form.final_lock_deadline || null,
      notes: form.notes || null,
    })
    form.name = ''
    form.code = ''
    form.initial_draft_deadline = ''
    form.final_lock_deadline = ''
    form.notes = ''
    await loadData()
  } catch (err) {
    formError.value = formatApiError(err, 'Failed to create session.')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadData()
})

onUnmounted(clearAutoRefresh)
</script>

<style scoped>
.session-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.session-item {
  border: 1px solid #e2e8f0;
  border-radius: 0.75rem;
  padding: 1rem;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.session-item:hover {
  border-color: #6366f1;
  box-shadow: 0 0 0 1px rgba(99, 102, 241, 0.12);
}

.session-item--current {
  border-color: #22c55e;
  background: rgba(34, 197, 94, 0.04);
}

.session-item__header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
}

.session-item__title {
  font-weight: 600;
}

.session-item__meta {
  margin-top: 0.5rem;
}

.session-item__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.75rem;
}
</style>
