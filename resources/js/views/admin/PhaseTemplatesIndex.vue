<template>
  <div>
    <PageHeader
      title="Phase Templates"
      subtitle="Upload the reference templates students should use for their Proposal, Phase 1, and Phase 2 submissions"
      breadcrumb="Phase Templates"
    />

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <div v-else class="row g-4">
      <div v-for="phase in PHASES" :key="phase.key" class="col-12">
        <AppCard :title="`${phase.label} Templates`">
          <div v-if="canManage" class="upload-row mb-3">
            <div class="row g-2 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Title (optional)</label>
                <input v-model="uploadForms[phase.key].title" type="text" class="form-control" placeholder="e.g. Proposal Format Template" />
              </div>
              <div class="col-md-4">
                <label class="form-label">File (PDF or Word)</label>
                <input
                  :ref="(el) => setFileInputRef(phase.key, el)"
                  type="file"
                  class="form-control"
                  accept=".pdf,.doc,.docx"
                  @change="onFileSelected(phase.key, $event)"
                />
              </div>
              <div class="col-md-4">
                <button
                  type="button"
                  class="btn btn-primary btn-sm"
                  :disabled="uploading === phase.key || !uploadForms[phase.key].file"
                  @click="submitUpload(phase.key)"
                >
                  <i class="bi bi-upload me-1"></i> Upload Template
                </button>
              </div>
            </div>
            <div v-if="uploadErrors[phase.key]" class="alert alert-danger py-2 mt-2 mb-0">
              {{ uploadErrors[phase.key] }}
            </div>
          </div>

          <EmptyState
            v-if="!templates[phase.key].length"
            title="No templates uploaded"
            :description="`Upload a ${phase.label.toLowerCase()} template so students know what format to follow.`"
            icon="bi bi-file-earmark-text"
          />

          <div v-else class="template-list">
            <div v-for="template in templates[phase.key]" :key="template.id" class="template-row">
              <div class="template-row__icon">
                <i class="bi bi-file-earmark-text"></i>
              </div>
              <div class="template-row__body">
                <div class="template-row__title">{{ template.title }}</div>
                <div class="text-muted small">
                  {{ template.original_filename }} · {{ template.file_size_label }}
                  <span v-if="template.uploaded_by?.name"> · Uploaded by {{ template.uploaded_by.name }}</span>
                  · {{ template.created_at }}
                </div>
                <div v-if="template.description" class="text-muted small mt-1">{{ template.description }}</div>
              </div>
              <div class="template-row__actions">
                <a :href="template.download_url" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-download me-1"></i> Download
                </a>
                <button
                  v-if="canManage"
                  type="button"
                  class="btn btn-outline-danger btn-sm"
                  :disabled="deletingId === template.id"
                  @click="removeTemplate(template)"
                >
                  <i class="bi bi-trash"></i>
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
import { computed, onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppCard from '@/components/ui/AppCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { fetchPhaseTemplates, uploadPhaseTemplate, deletePhaseTemplate } from '@/api/phaseTemplates'
import { useAuthStore } from '@/stores/auth'
import { confirmDialog } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { formatApiError } from '@/utils/apiErrors'

const authStore = useAuthStore()
const canManage = computed(() => authStore.canManagePhaseTemplates)

const PHASES = [
  { key: 'proposal', label: 'Proposal' },
  { key: 'phase_1', label: 'Phase-1' },
  { key: 'phase_2', label: 'Phase-2' },
]

const loading = ref(true)
const loadError = ref('')
const uploading = ref(null)
const deletingId = ref(null)
const fileInputRefs = {}

const templates = reactive({ proposal: [], phase_1: [], phase_2: [] })
const uploadForms = reactive({
  proposal: { title: '', file: null },
  phase_1: { title: '', file: null },
  phase_2: { title: '', file: null },
})
const uploadErrors = reactive({ proposal: '', phase_1: '', phase_2: '' })

const setFileInputRef = (phaseKey, el) => {
  if (el) fileInputRefs[phaseKey] = el
}

const onFileSelected = (phaseKey, event) => {
  uploadForms[phaseKey].file = event.target.files[0] || null
}

const loadTemplates = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchPhaseTemplates()
    templates.proposal = res.data.proposal || []
    templates.phase_1 = res.data.phase_1 || []
    templates.phase_2 = res.data.phase_2 || []
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load templates.')
  } finally {
    loading.value = false
  }
}

const submitUpload = async (phaseKey) => {
  const form = uploadForms[phaseKey]
  if (!form.file) return

  uploadErrors[phaseKey] = ''
  uploading.value = phaseKey
  try {
    await uploadPhaseTemplate({ phase: phaseKey, file: form.file, title: form.title })
    form.title = ''
    form.file = null
    if (fileInputRefs[phaseKey]) fileInputRefs[phaseKey].value = ''
    toast.success('Template uploaded.')
    await loadTemplates()
  } catch (err) {
    uploadErrors[phaseKey] = formatApiError(err, 'Failed to upload template.')
  } finally {
    uploading.value = null
  }
}

const removeTemplate = async (template) => {
  const confirmed = await confirmDialog.confirm({
    title: 'Delete Template',
    message: `Delete "${template.title}"? Students will no longer be able to download it.`,
    confirmText: 'Delete',
    variant: 'danger',
  })
  if (!confirmed) return

  deletingId.value = template.id
  try {
    await deletePhaseTemplate(template.id)
    toast.success('Template deleted.')
    await loadTemplates()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to delete template.'))
  } finally {
    deletingId.value = null
  }
}

onMounted(loadTemplates)
</script>

<style scoped>
.template-list {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.template-row {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.75rem 1rem;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  background: #fff;
}

.template-row__icon {
  font-size: 1.4rem;
  color: #7367f0;
  flex-shrink: 0;
}

.template-row__body {
  flex: 1;
  min-width: 0;
}

.template-row__title {
  font-weight: 600;
  color: #5e5873;
}

.template-row__actions {
  display: flex;
  gap: 0.5rem;
  flex-shrink: 0;
}

.upload-row {
  padding-bottom: 1rem;
  border-bottom: 1px solid #ebe9f1;
}
</style>
