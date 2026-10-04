<template>
  <div class="search-picker member-picker">
    <label v-if="label" class="form-label">{{ label }}</label>

    <div v-if="selectedStudents.length" class="selected-members mb-2">
      <span v-for="student in selectedStudents" :key="student.id" class="member-chip">
        <span class="member-chip__name">{{ student.name }}</span>
        <span class="member-chip__sap">SAP: {{ student.sap_id || student.registration_no || '—' }}</span>
        <button type="button" class="member-chip__remove" aria-label="Remove" @click="removeStudent(student.id)">
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
              <span class="search-picker__option-meta">SAP ID: {{ student.sap_id || student.registration_no || '—' }}</span>
            </button>
          </div>

          <div
            v-else
            class="search-picker__dropdown search-picker__dropdown--teleport search-picker__empty"
            :style="dropdownStyle"
          >
            {{ searchQuery ? 'No eligible students found for this search.' : 'No students available. All enrolled students may already be in a group.' }}
          </div>
        </div>
      </Teleport>
    </div>

    <small v-if="hint" class="text-muted d-block mt-1">{{ hint }}</small>
    <small v-if="maxReached" class="text-warning d-block mt-1">Maximum team members selected.</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useSearchPickerDropdown } from '@/composables/useSearchPickerDropdown'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  min: { type: Number, default: 1 },
  max: { type: Number, default: 4 },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Search by name or SAP ID...' },
  hint: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')
const openDropdown = ref(false)
const rootRef = ref(null)
const dropdownRef = ref(null)
const { dropdownStyle, updatePosition, containsTarget } = useSearchPickerDropdown(rootRef, openDropdown, dropdownRef)

const selectedStudents = computed(() =>
  props.options.filter((student) => props.modelValue.includes(student.id))
)

const maxReached = computed(() => props.modelValue.length >= props.max)

const filteredOptions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return props.options
    .filter((student) => !props.modelValue.includes(student.id))
    .filter((student) => {
      if (!q) return true
      const sap = (student.sap_id || student.registration_no || '').toLowerCase()
      return student.name?.toLowerCase().includes(q) || sap.includes(q) || student.email?.toLowerCase().includes(q)
    })
})

const openDropdownMenu = () => {
  openDropdown.value = true
  updatePosition()
}

const selectStudent = (student) => {
  if (maxReached.value) return
  emit('update:modelValue', [...props.modelValue, student.id])
  searchQuery.value = ''
  openDropdown.value = false
}

const removeStudent = (id) => {
  emit('update:modelValue', props.modelValue.filter((value) => value !== id))
}

const onDocumentClick = (event) => {
  if (!containsTarget(event.target)) {
    openDropdown.value = false
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))

watch(
  () => props.modelValue.length,
  () => {
    if (maxReached.value) openDropdown.value = false
  }
)
</script>

<style scoped>
.selected-members {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.member-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem 0.35rem 0.75rem;
  background: rgba(115, 103, 240, 0.12);
  border: 1px solid rgba(115, 103, 240, 0.25);
  border-radius: 999px;
  font-size: 0.82rem;
}

.member-chip__name {
  font-weight: 600;
  color: #5e5873;
}

.member-chip__sap {
  color: #7367f0;
}

.member-chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.25rem;
  line-height: 1;
}

.member-chip__remove:hover {
  color: #ea5455;
}
</style>
