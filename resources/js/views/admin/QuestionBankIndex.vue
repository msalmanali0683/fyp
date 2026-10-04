<template>
  <div>
    <PageHeader
      title="Evaluation Question Bank"
      subtitle="Define the questions evaluators answer for each phase, and how many marks each question is worth"
      breadcrumb="Question Bank"
    />

    <div v-if="loadError" class="alert alert-danger py-2">{{ loadError }}</div>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary"></div>
    </div>

    <div v-else class="row g-4">
      <div v-for="phase in PHASES" :key="phase.key" class="col-12">
        <AppCard :title="`${phase.label} Questions`" :subtitle="`Total possible marks: ${totalFor(phase.key)}`">
          <div v-if="canManage" class="add-row mb-3">
            <div class="row g-2 align-items-end">
              <div class="col-md-7">
                <label class="form-label">Question</label>
                <input v-model="newForms[phase.key].text" type="text" class="form-control" placeholder="e.g. Is the methodology sound?" />
              </div>
              <div class="col-md-3">
                <label class="form-label">Max Marks</label>
                <input v-model.number="newForms[phase.key].max_marks" type="number" min="1" max="1000" class="form-control" />
              </div>
              <div class="col-md-2">
                <button
                  type="button"
                  class="btn btn-primary btn-sm w-100"
                  :disabled="adding === phase.key || !newForms[phase.key].text || !newForms[phase.key].max_marks"
                  @click="submitNewQuestion(phase.key)"
                >
                  Add
                </button>
              </div>
            </div>
            <div v-if="addErrors[phase.key]" class="alert alert-danger py-2 mt-2 mb-0">{{ addErrors[phase.key] }}</div>
          </div>

          <EmptyState
            v-if="!questions[phase.key].length"
            title="No questions yet"
            :description="`Add questions so evaluators know what to score for ${phase.label.toLowerCase()}.`"
            icon="bi bi-patch-question"
          />

          <div v-else class="question-list">
            <div v-for="question in questions[phase.key]" :key="question.id" class="question-row" :class="{ 'question-row--inactive': !question.is_active }">
              <div class="question-row__body">
                <div v-if="editingId !== question.id">
                  <div class="question-row__text">{{ question.text }}</div>
                  <div class="text-muted small">
                    {{ question.max_marks }} marks
                    <AppBadge v-if="!question.is_active" variant="secondary" class="ms-2">Inactive</AppBadge>
                  </div>
                </div>
                <div v-else class="row g-2">
                  <div class="col-md-8">
                    <input v-model="editForm.text" type="text" class="form-control form-control-sm" />
                  </div>
                  <div class="col-md-4">
                    <input v-model.number="editForm.max_marks" type="number" min="1" max="1000" class="form-control form-control-sm" />
                  </div>
                </div>
              </div>
              <div v-if="canManage" class="question-row__actions">
                <template v-if="editingId === question.id">
                  <button type="button" class="btn btn-success btn-sm" :disabled="saving" @click="saveEdit(question)">Save</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="cancelEdit">Cancel</button>
                </template>
                <template v-else>
                  <button type="button" class="btn btn-outline-secondary btn-sm" @click="startEdit(question)">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button type="button" class="btn btn-outline-warning btn-sm" :disabled="saving" @click="toggleActive(question)">
                    {{ question.is_active ? 'Deactivate' : 'Activate' }}
                  </button>
                  <button type="button" class="btn btn-outline-danger btn-sm" :disabled="saving" @click="removeQuestion(question)">
                    <i class="bi bi-trash"></i>
                  </button>
                </template>
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
import AppBadge from '@/components/ui/AppBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { fetchQuestionBank, createQuestion, updateQuestion, deleteQuestion } from '@/api/questionBank'
import { useAuthStore } from '@/stores/auth'
import { confirmDialog } from '@/composables/useConfirm'
import { toast } from '@/composables/useToast'
import { formatApiError } from '@/utils/apiErrors'

const authStore = useAuthStore()
const canManage = computed(() => authStore.canManageQuestionBank)

const PHASES = [
  { key: 'proposal', label: 'Proposal' },
  { key: 'phase_1', label: 'Phase-1' },
  { key: 'phase_2', label: 'Phase-2' },
]

const loading = ref(true)
const loadError = ref('')
const adding = ref(null)
const saving = ref(false)
const editingId = ref(null)
const editForm = reactive({ text: '', max_marks: null })

const questions = reactive({ proposal: [], phase_1: [], phase_2: [] })
const newForms = reactive({
  proposal: { text: '', max_marks: null },
  phase_1: { text: '', max_marks: null },
  phase_2: { text: '', max_marks: null },
})
const addErrors = reactive({ proposal: '', phase_1: '', phase_2: '' })

const totalFor = (phaseKey) => questions[phaseKey].reduce((sum, q) => sum + (q.is_active ? q.max_marks : 0), 0)

const loadQuestions = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const res = await fetchQuestionBank()
    questions.proposal = res.data.proposal || []
    questions.phase_1 = res.data.phase_1 || []
    questions.phase_2 = res.data.phase_2 || []
  } catch (err) {
    loadError.value = formatApiError(err, 'Failed to load the question bank.')
  } finally {
    loading.value = false
  }
}

const submitNewQuestion = async (phaseKey) => {
  const form = newForms[phaseKey]
  addErrors[phaseKey] = ''
  adding.value = phaseKey
  try {
    await createQuestion({ phase: phaseKey, text: form.text, max_marks: form.max_marks })
    form.text = ''
    form.max_marks = null
    toast.success('Question added.')
    await loadQuestions()
  } catch (err) {
    addErrors[phaseKey] = formatApiError(err, 'Failed to add question.')
  } finally {
    adding.value = null
  }
}

const startEdit = (question) => {
  editingId.value = question.id
  editForm.text = question.text
  editForm.max_marks = question.max_marks
}

const cancelEdit = () => {
  editingId.value = null
}

const saveEdit = async (question) => {
  saving.value = true
  try {
    await updateQuestion(question.id, { text: editForm.text, max_marks: editForm.max_marks })
    toast.success('Question updated.')
    editingId.value = null
    await loadQuestions()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to update question.'))
  } finally {
    saving.value = false
  }
}

const toggleActive = async (question) => {
  saving.value = true
  try {
    await updateQuestion(question.id, { is_active: !question.is_active })
    toast.success(question.is_active ? 'Question deactivated.' : 'Question activated.')
    await loadQuestions()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to update question.'))
  } finally {
    saving.value = false
  }
}

const removeQuestion = async (question) => {
  const confirmed = await confirmDialog.confirm({
    title: 'Delete Question',
    message: `Delete "${question.text}"? This only works if no evaluator has answered it yet.`,
    confirmText: 'Delete',
    variant: 'danger',
  })
  if (!confirmed) return

  saving.value = true
  try {
    await deleteQuestion(question.id)
    toast.success('Question deleted.')
    await loadQuestions()
  } catch (err) {
    toast.error(formatApiError(err, 'Failed to delete question.'))
  } finally {
    saving.value = false
  }
}

onMounted(loadQuestions)
</script>

<style scoped>
.question-list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.question-row {
  display: flex;
  align-items: center;
  gap: 0.85rem;
  padding: 0.65rem 1rem;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  background: #fff;
}

.question-row--inactive {
  opacity: 0.6;
}

.question-row__body {
  flex: 1;
  min-width: 0;
}

.question-row__text {
  font-weight: 600;
  color: #5e5873;
}

.question-row__actions {
  display: flex;
  gap: 0.4rem;
  flex-shrink: 0;
}

.add-row {
  padding-bottom: 1rem;
  border-bottom: 1px solid #ebe9f1;
}
</style>
