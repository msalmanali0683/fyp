<template>
  <div>
    <PageHeader title="Proposal Settings" subtitle="Team size and supervisor workload limits" breadcrumb="Proposal Settings" />

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <div v-else class="row g-4">
      <div class="col-12 col-lg-6">
        <AppCard title="Group Size Limits" subtitle="Minimum and maximum students per proposal group">
          <form @submit.prevent="saveSettings">
            <div v-if="formError" class="alert alert-danger py-2">{{ formError }}</div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Minimum Members</label>
                <input v-model.number="form.min_members" type="number" min="2" max="20" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Maximum Members</label>
                <input v-model.number="form.max_members" type="number" min="2" max="20" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Minimum Evaluators</label>
                <input v-model.number="form.min_evaluators" type="number" min="1" max="10" class="form-control" required />
              </div>
              <div class="col-md-6">
                <label class="form-label">Maximum Evaluators</label>
                <input v-model.number="form.max_evaluators" type="number" min="1" max="10" class="form-control" required />
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3" :disabled="saving">Save Group Limits</button>
          </form>
        </AppCard>
      </div>

      <div class="col-12 col-lg-6">
        <AppCard title="Default Supervisor Limits" subtitle="Maximum active groups each supervisor can handle per phase">
          <form @submit.prevent="saveSettings">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Proposal</label>
                <input v-model.number="form.supervisor_limits.proposal" type="number" min="0" max="100" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Phase-1</label>
                <input v-model.number="form.supervisor_limits.phase_1" type="number" min="0" max="100" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Phase-2</label>
                <input v-model.number="form.supervisor_limits.phase_2" type="number" min="0" max="100" class="form-control" required />
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3" :disabled="saving">Save Supervisor Limits</button>
          </form>
        </AppCard>
      </div>

      <div class="col-12 col-lg-6">
        <AppCard title="Default Evaluator Limits" subtitle="Maximum projects each evaluator can be assigned per phase">
          <form @submit.prevent="saveSettings">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Proposal</label>
                <input v-model.number="form.evaluator_limits.proposal" type="number" min="0" max="100" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Phase-1</label>
                <input v-model.number="form.evaluator_limits.phase_1" type="number" min="0" max="100" class="form-control" required />
              </div>
              <div class="col-md-4">
                <label class="form-label">Phase-2</label>
                <input v-model.number="form.evaluator_limits.phase_2" type="number" min="0" max="100" class="form-control" required />
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm mt-3" :disabled="saving">Save Evaluator Limits</button>
          </form>
        </AppCard>
      </div>

      <div v-for="phase in PHASES" :key="phase.key" class="col-12">
        <AppCard
          :title="`Evaluator Visibility — ${phase.label}`"
          subtitle="Control who can see an evaluator's identity and marks/comments for this phase only"
        >
          <form @submit.prevent="saveSettings">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th></th>
                    <th class="text-center">Identity — During Review</th>
                    <th class="text-center">Identity — After Decision</th>
                    <th class="text-center">Marks/Comments — During Review</th>
                    <th class="text-center">Marks/Comments — After Decision</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <th class="text-nowrap">Supervisor</th>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].supervisor_view_identity_during_review" type="checkbox" class="form-check-input" />
                    </td>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].supervisor_view_identity_after_decision" type="checkbox" class="form-check-input" />
                    </td>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].supervisor_view_marks_during_review" type="checkbox" class="form-check-input" />
                    </td>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].supervisor_view_marks_after_decision" type="checkbox" class="form-check-input" />
                    </td>
                  </tr>
                  <tr>
                    <th class="text-nowrap">Student (team)</th>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].student_view_identity_during_review" type="checkbox" class="form-check-input" />
                    </td>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].student_view_identity_after_decision" type="checkbox" class="form-check-input" />
                    </td>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].student_view_marks_during_review" type="checkbox" class="form-check-input" />
                    </td>
                    <td class="text-center">
                      <input v-model="form.evaluator_visibility[phase.key].student_view_marks_after_decision" type="checkbox" class="form-check-input" />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <small class="text-muted d-block mt-2">
              "During Review" applies while {{ phase.label }} is still being evaluated. "After Decision" applies once {{ phase.label }} has been fully approved.
              Admin and FYP Committee always see everything regardless of this setting.
            </small>
            <button type="submit" class="btn btn-primary btn-sm mt-3" :disabled="saving">Save {{ phase.label }} Visibility</button>
          </form>
        </AppCard>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import { fetchProposalSettings, updateProposalSettings } from '@/api/proposals'
import { formatApiError } from '@/utils/apiErrors'

const loading = ref(true)
const saving = ref(false)
const formError = ref('')

const PHASES = [
  { key: 'proposal', label: 'Proposal' },
  { key: 'phase_1', label: 'Phase 1' },
  { key: 'phase_2', label: 'Phase 2' },
]

const defaultVisibilityForPhase = () => ({
  supervisor_view_identity_during_review: true,
  supervisor_view_identity_after_decision: true,
  supervisor_view_marks_during_review: true,
  supervisor_view_marks_after_decision: true,
  student_view_identity_during_review: false,
  student_view_identity_after_decision: false,
  student_view_marks_during_review: true,
  student_view_marks_after_decision: true,
})

const form = reactive({
  min_members: 3,
  max_members: 5,
  min_evaluators: 2,
  max_evaluators: 3,
  supervisor_limits: {
    proposal: 5,
    phase_1: 5,
    phase_2: 5,
  },
  evaluator_limits: {
    proposal: 5,
    phase_1: 5,
    phase_2: 5,
  },
  evaluator_visibility: {
    proposal: defaultVisibilityForPhase(),
    phase_1: defaultVisibilityForPhase(),
    phase_2: defaultVisibilityForPhase(),
  },
})

const loadSettings = async () => {
  const res = await fetchProposalSettings()
  const data = res.data
  form.min_members = data.team_limits?.min_members ?? data.min_members
  form.max_members = data.team_limits?.max_members ?? data.max_members
  form.min_evaluators = data.team_limits?.min_evaluators ?? data.min_evaluators
  form.max_evaluators = data.team_limits?.max_evaluators ?? data.max_evaluators
  form.supervisor_limits = {
    proposal: data.default_supervisor_limits?.proposal ?? 5,
    phase_1: data.default_supervisor_limits?.phase_1 ?? 5,
    phase_2: data.default_supervisor_limits?.phase_2 ?? 5,
  }
  form.evaluator_limits = {
    proposal: data.default_evaluator_limits?.proposal ?? 5,
    phase_1: data.default_evaluator_limits?.phase_1 ?? 5,
    phase_2: data.default_evaluator_limits?.phase_2 ?? 5,
  }
  PHASES.forEach((phase) => {
    Object.assign(form.evaluator_visibility[phase.key], data.evaluator_visibility?.[phase.key] || {})
  })
}

onMounted(async () => {
  try {
    await loadSettings()
  } finally {
    loading.value = false
  }
})

const saveSettings = async () => {
  formError.value = ''
  saving.value = true
  try {
    await updateProposalSettings({ ...form })
    await loadSettings()
  } catch (err) {
    formError.value = formatApiError(err, 'Failed to save settings.')
  } finally {
    saving.value = false
  }
}
</script>
