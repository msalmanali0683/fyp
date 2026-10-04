<template>
  <div class="search-picker session-picker">
    <label v-if="label" class="form-label">{{ label }}</label>

    <div v-if="selectedSession" class="selected-session mb-2">
      <span class="session-chip">
        <span class="session-chip__name">{{ selectedSession.name }}</span>
        <span class="session-chip__code">{{ selectedSession.code }}</span>
        <button type="button" class="session-chip__remove" aria-label="Clear session" @click="clearSelection">
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
              v-for="session in filteredOptions"
              :key="session.id"
              type="button"
              class="search-picker__option"
              @mousedown.prevent="selectSession(session)"
            >
              <span class="search-picker__option-name">{{ session.name }}</span>
              <span class="search-picker__option-meta">
                Code: {{ session.code }}
                <template v-if="session.program_name"> · {{ session.program_name }}</template>
                <template v-if="session.lifecycle_phase_label"> · {{ session.lifecycle_phase_label }}</template>
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
    <small v-if="required && !modelValue" class="text-danger d-block mt-1">Proposal session is required.</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useSearchPickerDropdown } from '@/composables/useSearchPickerDropdown'

const props = defineProps({
  modelValue: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Search proposal sessions by name or code...' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  programId: { type: [Number, String, null], default: null },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')
const openDropdown = ref(false)
const rootRef = ref(null)
const dropdownRef = ref(null)
const { dropdownStyle, updatePosition, containsTarget } = useSearchPickerDropdown(rootRef, openDropdown, dropdownRef)

const normalizedValue = computed(() => (props.modelValue || '').trim().toUpperCase())

const scopedOptions = computed(() => {
  if (!props.programId) {
    return props.options
  }

  return props.options.filter((session) => Number(session.program_id) === Number(props.programId))
})

const selectedSession = computed(() => {
  const match = scopedOptions.value.find(
    (session) => (session.code || '').toUpperCase() === normalizedValue.value
  )

  if (match) {
    return match
  }

  if (normalizedValue.value) {
    return {
      code: normalizedValue.value,
      name: normalizedValue.value,
      legacy: true,
    }
  }

  return null
})

const filteredOptions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()

  return scopedOptions.value
    .filter((session) => (session.code || '').toUpperCase() !== normalizedValue.value)
    .filter((session) => {
      if (!q) {
        return true
      }

      return (
        session.name?.toLowerCase().includes(q) ||
        session.code?.toLowerCase().includes(q) ||
        session.program_name?.toLowerCase().includes(q)
      )
    })
})

const emptyMessage = computed(() => {
  if (!props.programId) {
    return 'Select a program first to see proposal sessions.'
  }

  if (searchQuery.value.trim()) {
    return 'No proposal sessions found for this search.'
  }

  return 'No proposal sessions have been created for this program yet.'
})

const openDropdownMenu = () => {
  if (props.disabled) {
    return
  }

  openDropdown.value = true
  updatePosition()
}

const selectSession = (session) => {
  emit('update:modelValue', session.code)
  searchQuery.value = ''
  openDropdown.value = false
}

const clearSelection = () => {
  emit('update:modelValue', '')
  searchQuery.value = ''
}

const onDocumentClick = (event) => {
  if (!containsTarget(event.target)) {
    openDropdown.value = false
  }
}

watch(
  () => props.programId,
  () => {
    if (!normalizedValue.value) {
      return
    }

    const stillValid = scopedOptions.value.some(
      (session) => (session.code || '').toUpperCase() === normalizedValue.value
    )

    if (!stillValid) {
      emit('update:modelValue', '')
    }
  }
)

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<style scoped>
.selected-session {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.session-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem 0.35rem 0.75rem;
  background: rgba(115, 103, 240, 0.12);
  border: 1px solid rgba(115, 103, 240, 0.25);
  border-radius: 999px;
  font-size: 0.82rem;
}

.session-chip__name {
  font-weight: 600;
  color: #5e5873;
}

.session-chip__code {
  color: #7367f0;
  font-weight: 600;
}

.session-chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.25rem;
  line-height: 1;
}

.session-chip__remove:hover {
  color: #ea5455;
}
</style>
