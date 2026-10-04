<template>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Department</label>
      <select
        v-model="departmentId"
        class="form-select"
        :required="required"
        :disabled="disabled || loading"
      >
        <option :value="null">Select department</option>
        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
          {{ dept.name }}
        </option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Program</label>
      <select
        v-model="programId"
        class="form-select"
        :required="required"
        :disabled="disabled || loading || !departmentId"
      >
        <option :value="null">{{ departmentId ? 'Select program' : 'Select department first' }}</option>
        <option v-for="program in programsForDepartment" :key="program.id" :value="program.id">
          {{ program.name }}
        </option>
      </select>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { fetchDepartments } from '@/api/programs'

const departmentId = defineModel('departmentId', { type: [Number, String], default: null })
const programId = defineModel('programId', { type: [Number, String], default: null })

defineProps({
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})

const departments = ref([])
const loading = ref(false)

const programsForDepartment = computed(() => {
  if (!departmentId.value) return []
  const dept = departments.value.find((d) => d.id === Number(departmentId.value))
  return (dept?.programs || []).filter((p) => p.is_active !== false)
})

onMounted(async () => {
  loading.value = true
  try {
    const res = await fetchDepartments()
    departments.value = res.data?.departments || []
  } finally {
    loading.value = false
  }
})

watch(departmentId, () => {
  if (!programId.value) return
  const stillValid = programsForDepartment.value.some((p) => p.id === Number(programId.value))
  if (!stillValid) {
    programId.value = null
  }
})
</script>
