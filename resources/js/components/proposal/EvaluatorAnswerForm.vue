<template>
  <div class="evaluator-answer-form">
    <div v-if="!questions.length" class="alert alert-warning py-2">
      No evaluation questions have been configured for this phase yet. Ask the FYP office to set up the question bank before evaluating.
    </div>
    <div v-for="question in questions" :key="question.id" class="answer-row mb-3">
      <label class="form-label d-flex justify-content-between mb-1">
        <span>{{ question.text }}</span>
        <span class="text-muted small">/ {{ question.max_marks }}</span>
      </label>
      <input
        type="number"
        :min="0"
        :max="question.max_marks"
        class="form-control form-control-sm mb-1"
        style="max-width: 140px"
        :disabled="disabled"
        :value="marksFor(question.id)"
        @input="updateMarks(question.id, $event.target.value)"
      />
      <textarea
        class="form-control form-control-sm"
        rows="2"
        placeholder="Optional comment for this question"
        :disabled="disabled"
        :value="commentFor(question.id)"
        @input="updateComment(question.id, $event.target.value)"
      ></textarea>
    </div>
    <div v-if="questions.length" class="text-muted small">
      Total: {{ totalMarks }} / {{ totalMaxMarks }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  questions: { type: Array, default: () => [] },
  modelValue: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const marksFor = (questionId) => props.modelValue[questionId]?.marks ?? ''
const commentFor = (questionId) => props.modelValue[questionId]?.comment ?? ''

const updateMarks = (questionId, rawValue) => {
  const question = props.questions.find((q) => q.id === questionId)
  let value = rawValue

  if (value !== '' && question) {
    const numeric = Number(value)
    if (!Number.isNaN(numeric)) {
      value = String(Math.min(Math.max(numeric, 0), question.max_marks))
    }
  }

  emit('update:modelValue', {
    ...props.modelValue,
    [questionId]: { ...props.modelValue[questionId], marks: value },
  })
}

const updateComment = (questionId, value) => {
  emit('update:modelValue', {
    ...props.modelValue,
    [questionId]: { ...props.modelValue[questionId], comment: value },
  })
}

const totalMarks = computed(() =>
  props.questions.reduce((sum, question) => sum + (Number(props.modelValue[question.id]?.marks) || 0), 0)
)

const totalMaxMarks = computed(() => props.questions.reduce((sum, question) => sum + question.max_marks, 0))

defineExpose({ totalMarks, totalMaxMarks })
</script>

<style scoped>
.answer-row {
  padding-bottom: 0.5rem;
  border-bottom: 1px dashed #ebe9f1;
}

.answer-row:last-of-type {
  border-bottom: none;
}
</style>
