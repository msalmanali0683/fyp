<template>
  <div v-if="phaseRow" class="workflow-card mb-4">
    <div class="workflow-card__header">
      <i class="bi bi-file-earmark-arrow-up me-2"></i>
      {{ phaseRow.phase_label || 'Phase Deliverable' }}
    </div>
    <div class="workflow-card__body">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <AppBadge :variant="statusVariant">{{ phaseRow.status_label || phaseRow.status }}</AppBadge>
        <AppBadge variant="info">{{ project?.workflow_stage_label || phaseRow.workflow_stage_label }}</AppBadge>
        <span v-if="phaseRow.submitted_at" class="text-muted small">Submitted: {{ formatDate(phaseRow.submitted_at) }}</span>
      </div>

      <div v-if="phaseRow.feedback" class="alert alert-warning py-2">
        <strong>Reviewer feedback:</strong> {{ phaseRow.feedback }}
      </div>

      <div v-if="deliverableFileUrl" class="deliverable-document mb-3">
        <label class="form-label mb-2">{{ phaseRow.phase_label }} Document</label>
        <div class="proposal-pdf-panel">
          <div class="proposal-pdf-viewer">
            <ProposalPdfPreview :url="deliverableFileUrl" />
            <a
              :href="deliverableFileUrl"
              target="_blank"
              rel="noopener"
              class="proposal-doc-link mt-2 d-inline-block"
            >
              <i class="bi bi-box-arrow-up-right me-1"></i>
              Open latest uploaded file in new tab
            </a>
          </div>
        </div>
      </div>

      <div v-else-if="!canEdit" class="alert alert-warning py-2 mb-3">
        No {{ phaseRow.phase_label }} file has been uploaded yet.
      </div>

      <div v-if="phaseRow.content && !canEdit" class="mb-3">
        <label class="form-label">Submission Notes</label>
        <div class="submission-notes">{{ phaseRow.content }}</div>
      </div>

      <div v-if="canEdit" class="row g-3">
        <div class="col-12">
          <label class="form-label">Submission Notes</label>
          <textarea v-model="content" class="form-control" rows="4" placeholder="Optional notes about this phase submission"></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">{{ deliverableFileUrl ? 'Replace Phase File (PDF)' : 'Phase File (PDF)' }}</label>
          <input type="file" accept="application/pdf,.pdf" class="form-control" @change="onFileChange" />
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-outline-primary btn-sm" :disabled="saving" @click="saveDraft">
            Save Draft
          </button>
          <button
            v-if="canInitialSubmit"
            type="button"
            class="btn btn-primary btn-sm"
            :disabled="saving || !canSubmit"
            @click="submitDeliverable"
          >
            Submit for Review
          </button>
        </div>
        <div v-if="!sessionContext?.phase_deliverable_started" class="col-12">
          <div class="alert alert-info py-2 mb-0">
            Upload at least one PDF file before the phase start deadline to mark your team as started.
          </div>
        </div>
      </div>

      <div v-else-if="phaseRow.workflow_stage && phaseRow.workflow_stage !== 'draft'" class="alert alert-info py-2 mb-0">
        Deliverable is at <strong>{{ project?.workflow_stage_label || phaseRow.workflow_stage_label }}</strong>.
        <span v-if="phaseRow.workflow_stage === 'revision_required'">
          The project leader must upload a revised file and use <strong>Resubmit Deliverable</strong> below.
        </span>
        <span v-else> Review the latest uploaded file above before taking action. </span>
      </div>

      <div v-else-if="!canEdit && props.project?.viewer_is_project_leader === false" class="alert alert-info py-2 mb-0">
        Only the project leader can upload and submit phase deliverables.
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import ProposalPdfPreview from '@/components/proposal/ProposalPdfPreview.vue'
import { submitPhase, updatePhase, uploadPhaseAttachment } from '@/api/projects'
import { formatApiError } from '@/utils/apiErrors'
import { storageUrl } from '@/utils/baseUrl'

const props = defineProps({
  project: { type: Object, required: true },
  sessionContext: { type: Object, default: null },
})

const emit = defineEmits(['updated', 'error'])

const saving = ref(false)
const content = ref('')
const pendingFile = ref(null)

const phaseRow = computed(() => {
  const phase = props.project?.current_phase
  if (!phase || phase === 'proposal') return null
  return props.project?.phases?.find((row) => row.phase === phase) || null
})

const deliverableFileUrl = computed(() => {
  if (!phaseRow.value) return null
  return storageUrl(phaseRow.value.attachment || phaseRow.value.attachment_url)
})

const canEdit = computed(() => {
  if (!phaseRow.value?.is_editable) return false
  if (!props.project?.viewer_is_project_leader) return false
  return !!props.sessionContext?.can_edit_phase_deliverable
})

const canInitialSubmit = computed(() => canEdit.value && (phaseRow.value?.workflow_stage || 'draft') === 'draft')

const canSubmit = computed(() => !!(deliverableFileUrl.value || pendingFile.value || phaseRow.value?.attachment))

const statusVariant = computed(() => {
  const map = {
    draft: 'secondary',
    submitted: 'info',
    approved: 'success',
    revision_required: 'warning',
    rejected: 'danger',
  }
  return map[phaseRow.value?.status] || 'secondary'
})

watch(
  () => phaseRow.value,
  (row) => {
    content.value = row?.content || ''
    pendingFile.value = null
  },
  { immediate: true }
)

const formatDate = (value) => (value ? new Date(value).toLocaleString() : '')

const onFileChange = (event) => {
  pendingFile.value = event.target.files?.[0] || null
}

const saveDraft = async () => {
  if (!phaseRow.value) return
  saving.value = true
  try {
    if (pendingFile.value) {
      await uploadPhaseAttachment(props.project.id, phaseRow.value.phase, pendingFile.value)
      pendingFile.value = null
    }
    await updatePhase(props.project.id, phaseRow.value.phase, { content: content.value })
    emit('updated')
  } catch (err) {
    emit('error', formatApiError(err, 'Failed to save phase draft.'))
  } finally {
    saving.value = false
  }
}

const submitDeliverable = async () => {
  if (!phaseRow.value) return
  saving.value = true
  try {
    if (pendingFile.value) {
      await uploadPhaseAttachment(props.project.id, phaseRow.value.phase, pendingFile.value)
      pendingFile.value = null
    }
    if (content.value !== (phaseRow.value.content || '')) {
      await updatePhase(props.project.id, phaseRow.value.phase, { content: content.value })
    }
    await submitPhase(props.project.id, phaseRow.value.phase)
    emit('updated')
  } catch (err) {
    emit('error', formatApiError(err, 'Failed to submit phase deliverable.'))
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.proposal-pdf-panel {
  display: flex;
  flex-direction: column;
  min-height: 420px;
}

.proposal-pdf-viewer {
  display: flex;
  flex-direction: column;
  flex: 1;
}

.proposal-doc-link {
  color: #7367f0;
  font-weight: 500;
  text-decoration: none;
}

.proposal-doc-link:hover {
  text-decoration: underline;
}

.submission-notes {
  background: #f8f8f8;
  border-radius: 0.35rem;
  padding: 0.75rem;
  white-space: pre-wrap;
}
</style>
