<template>
  <div class="search-picker session-student-picker">
    <label v-if="label" class="form-label">{{ label }}</label>

    <div v-if="selectedStudent" class="selected-student mb-2">
      <span class="student-chip">
        <span class="student-chip__name">{{ selectedStudent.name }}</span>
        <span class="student-chip__meta">
          SAP: {{ selectedStudent.sap_id || selectedStudent.registration_no || '—' }}
          <template v-if="projectTitle(selectedStudent)"> · Project: {{ projectTitle(selectedStudent) }}</template>
        </span>
        <button type="button" class="student-chip__remove" aria-label="Clear student" @click="clearSelection">
          <i class="bi bi-x-lg"></i>
        </button>
      </span>
    </div>

    <div ref="rootRef" class="search-picker__control">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input
          v-model="searchQuery"
          type="text"
          class="form-control"
          :placeholder="placeholder"
          :disabled="disabled"
          autocomplete="off"
          @focus="openDropdownMenu"
          @input="openDropdownMenu"
        />
      </div>

      <Teleport to="body">
        <div v-if="openDropdown" ref="dropdownRef">
          <div
            v-if="filteredOptions.length"
            class="search-picker__dropdown search-picker__dropdown--teleport"
            :style="dropdownStyle"
          >
            <button
              v-for="student in filteredOptions"
              :key="student.id"
              type="button"
              class="search-picker__option"
              @mousedown.prevent="selectStudent(student)"
            >
              <span class="search-picker__option-name">{{ student.name }}</span>
              <span class="search-picker__option-meta">
                SAP: {{ student.sap_id || student.registration_no || '—' }}
                · {{ student.email }}
                <template v-if="projectTitle(student)"> · Project: {{ projectTitle(student) }}</template>
              </span>
            </button>
          </div>

          <div
            v-else
            class="search-picker__dropdown search-picker__dropdown--teleport search-picker__empty"
            :style="dropdownStyle"
          >
            {{ emptyMessage }}
          </div>
        </div>
      </Teleport>
    </div>

    <small v-if="hint" class="text-muted d-block mt-1">{{ hint }}</small>
    <small v-if="required && !modelValue" class="text-danger d-block mt-1">Student is required.</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useSearchPickerDropdown } from '@/composables/useSearchPickerDropdown'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  options: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Search by student name, SAP ID, or project title...' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')
const openDropdown = ref(false)
const rootRef = ref(null)
const dropdownRef = ref(null)
const { dropdownStyle, updatePosition, containsTarget } = useSearchPickerDropdown(rootRef, openDropdown, dropdownRef)

const selectedStudent = computed(() =>
  props.options.find((student) => Number(student.id) === Number(props.modelValue)) || null
)

const projectTitle = (student) => student?.team_status?.project_title || ''

const filteredOptions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()

  return props.options.filter((student) => {
    if (Number(student.id) === Number(props.modelValue)) {
      return false
    }

    if (!q) {
      return true
    }

    const sap = (student.sap_id || student.registration_no || '').toLowerCase()
    const project = projectTitle(student).toLowerCase()

    return (
      student.name?.toLowerCase().includes(q) ||
      student.email?.toLowerCase().includes(q) ||
      sap.includes(q) ||
      project.includes(q)
    )
  })
})

const emptyMessage = computed(() => {
  if (props.disabled) {
    return 'Select a proposal session first.'
  }

  if (searchQuery.value.trim()) {
    return 'No students found for this search.'
  }

  return 'No students enrolled in this session.'
})

const openDropdownMenu = () => {
  if (props.disabled) {
    return
  }

  openDropdown.value = true
  updatePosition()
}

const selectStudent = (student) => {
  emit('update:modelValue', student.id)
  searchQuery.value = ''
  openDropdown.value = false
}

const clearSelection = () => {
  emit('update:modelValue', null)
  searchQuery.value = ''
}

const onDocumentClick = (event) => {
  if (!containsTarget(event.target)) {
    openDropdown.value = false
  }
}

watch(
  () => props.options,
  () => {
    if (props.modelValue && !selectedStudent.value) {
      emit('update:modelValue', null)
    }
  }
)

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<style scoped>
.selected-student {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.student-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem 0.35rem 0.75rem;
  background: rgba(115, 103, 240, 0.12);
  border: 1px solid rgba(115, 103, 240, 0.25);
  border-radius: 999px;
  font-size: 0.82rem;
}

.student-chip__name {
  font-weight: 600;
  color: #5e5873;
}

.student-chip__meta {
  color: #7367f0;
}

.student-chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.25rem;
  line-height: 1;
}

.student-chip__remove:hover {
  color: #ea5455;
}
</style>
