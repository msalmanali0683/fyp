<template>
  <form @submit.prevent="onSubmit">
    <div class="row g-3">
      <div v-if="mode === 'staff'" class="col-12">
        <ProjectPicker v-model="form.project_id" :options="projects" label="Project" required />
      </div>
      <div class="col-12">
        <label class="form-label">Subject</label>
        <input v-model="form.subject" type="text" class="form-control" maxlength="255" required />
      </div>
      <div class="col-12">
        <label class="form-label">Message</label>
        <textarea v-model="form.message" class="form-control" rows="4" required placeholder="Describe the problem or question..."></textarea>
      </div>
      <div v-if="error" class="col-12">
        <div class="alert alert-danger py-2 mb-0">{{ error }}</div>
      </div>
      <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm" :disabled="submitting || !canSubmit">
          Submit Query
        </button>
        <button v-if="showCancel" type="button" class="btn btn-outline-secondary btn-sm" :disabled="submitting" @click="emit('cancel')">
          Cancel
        </button>
      </div>
    </div>
  </form>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import ProjectPicker from '@/components/queries/ProjectPicker.vue'

const props = defineProps({
  mode: { type: String, default: 'student' },
  projects: { type: Array, default: () => [] },
  submitting: { type: Boolean, default: false },
  error: { type: String, default: '' },
  showCancel: { type: Boolean, default: false },
})

const emit = defineEmits(['submit', 'cancel'])

const form = reactive({
  project_id: null,
  subject: '',
  message: '',
})

watch(
  () => props.projects,
  (list) => {
    if (props.mode === 'staff' && list.length === 1 && !form.project_id) {
      form.project_id = list[0].id
    }
  },
  { immediate: true }
)

const canSubmit = computed(() => {
  if (!form.subject.trim() || !form.message.trim()) {
    return false
  }

  if (props.mode === 'staff' && !form.project_id) {
    return false
  }

  return true
})

const onSubmit = () => {
  if (!canSubmit.value) {
    return
  }

  emit('submit', {
    project_id: props.mode === 'staff' ? form.project_id : undefined,
    subject: form.subject.trim(),
    message: form.message.trim(),
  })
}

const reset = () => {
  form.subject = ''
  form.message = ''
  form.project_id = props.projects.length === 1 ? props.projects[0].id : null
}

defineExpose({ reset })
</script>
