<template>
  <div class="search-picker supervisor-picker">
    <label v-if="label" class="form-label">{{ label }}</label>

    <div v-if="selectedSupervisor" class="selected-supervisor mb-2">
      <span class="supervisor-chip">
        <span class="supervisor-chip__name">{{ selectedSupervisor.name }}</span>
        <span class="supervisor-chip__email">{{ selectedSupervisor.email }}</span>
        <button type="button" class="supervisor-chip__remove" aria-label="Clear supervisor" @click="clearSelection">
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
              v-for="supervisor in filteredOptions"
              :key="supervisor.id"
              type="button"
              class="search-picker__option"
              @mousedown.prevent="selectSupervisor(supervisor)"
            >
              <span class="search-picker__option-name">{{ supervisor.name }}</span>
              <span class="search-picker__option-meta">
                {{ supervisor.email }}
                <template v-if="supervisor.remaining_capacity != null">
                  · {{ supervisor.remaining_capacity }} slot{{ supervisor.remaining_capacity === 1 ? '' : 's' }} left
                </template>
                <span v-if="supervisor.remaining_capacity === 0" class="text-danger"> · Full</span>
              </span>
            </button>
          </div>

          <div
            v-else
            class="search-picker__dropdown search-picker__dropdown--teleport search-picker__empty"
            :style="dropdownStyle"
          >
            {{ searchQuery ? 'No supervisors found for this search.' : 'No supervisors available.' }}
          </div>
        </div>
      </Teleport>
    </div>

    <small v-if="hint" class="text-muted d-block mt-1">{{ hint }}</small>
    <div v-if="capacityWarning" class="alert alert-warning py-2 mt-2 mb-0 small">
      <i class="bi bi-exclamation-triangle me-1"></i>
      {{ capacityWarning }}
    </div>
    <small v-if="required && !modelValue" class="text-danger d-block mt-1">Supervisor is required.</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useSearchPickerDropdown } from '@/composables/useSearchPickerDropdown'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  options: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Search by name or email...' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')
const openDropdown = ref(false)
const rootRef = ref(null)
const dropdownRef = ref(null)
const { dropdownStyle, updatePosition, containsTarget } = useSearchPickerDropdown(rootRef, openDropdown, dropdownRef)

const selectedSupervisor = computed(() =>
  props.options.find((supervisor) => Number(supervisor.id) === Number(props.modelValue)) || null
)

const capacityWarning = computed(() => {
  const supervisor = selectedSupervisor.value
  if (!supervisor) {
    return ''
  }

  if (supervisor.remaining_capacity === 0) {
    return `${supervisor.name} has reached the maximum number of groups they can supervise for this phase. Choose another supervisor.`
  }

  if (supervisor.remaining_capacity === 1) {
    return `${supervisor.name} has only 1 supervision slot left.`
  }

  return ''
})

const filteredOptions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()

  return props.options.filter((supervisor) => {
    if (Number(supervisor.id) === Number(props.modelValue)) {
      return false
    }

    if (!q) {
      return true
    }

    return (
      supervisor.name?.toLowerCase().includes(q) ||
      supervisor.email?.toLowerCase().includes(q)
    )
  })
})

const openDropdownMenu = () => {
  openDropdown.value = true
  updatePosition()
}

const selectSupervisor = (supervisor) => {
  emit('update:modelValue', supervisor.id)
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

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<style scoped>
.selected-supervisor {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.supervisor-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem 0.35rem 0.75rem;
  background: rgba(40, 199, 111, 0.12);
  border: 1px solid rgba(40, 199, 111, 0.25);
  border-radius: 999px;
  font-size: 0.82rem;
}

.supervisor-chip__name {
  font-weight: 600;
  color: #5e5873;
}

.supervisor-chip__email {
  color: #28c76f;
}

.supervisor-chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.25rem;
  line-height: 1;
}

.supervisor-chip__remove:hover {
  color: #ea5455;
}
</style>
