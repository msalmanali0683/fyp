<template>
  <div class="search-picker project-picker">
    <label v-if="label" class="form-label">{{ label }}</label>

    <div v-if="selectedProject" class="selected-project mb-2">
      <span class="project-chip">
        <span class="project-chip__name">{{ selectedProject.title }}</span>
        <span v-if="selectedProject.student_name" class="project-chip__meta">{{ selectedProject.student_name }}</span>
        <button type="button" class="project-chip__remove" aria-label="Clear project" @click="clearSelection">
          <i class="bi bi-x-lg"></i>
        </button>
      </span>
    </div>

    <div v-else ref="rootRef" class="search-picker__control">
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
              v-for="project in filteredOptions"
              :key="project.id"
              type="button"
              class="search-picker__option"
              @mousedown.prevent="selectProject(project)"
            >
              <span class="search-picker__option-name">{{ project.title }}</span>
              <span v-if="project.student_name" class="search-picker__option-meta">{{ project.student_name }}</span>
            </button>
          </div>

          <div
            v-else
            class="search-picker__dropdown search-picker__dropdown--teleport search-picker__empty"
            :style="dropdownStyle"
          >
            {{ searchQuery ? 'No projects found for this search.' : 'No projects available.' }}
          </div>
        </div>
      </Teleport>
    </div>

    <small v-if="hint" class="text-muted d-block mt-1">{{ hint }}</small>
    <small v-if="required && !modelValue" class="text-danger d-block mt-1">Select which project this concerns.</small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useSearchPickerDropdown } from '@/composables/useSearchPickerDropdown'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  options: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Search by project title...' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')
const openDropdown = ref(false)
const rootRef = ref(null)
const dropdownRef = ref(null)
const { dropdownStyle, updatePosition, containsTarget } = useSearchPickerDropdown(rootRef, openDropdown, dropdownRef)

const selectedProject = computed(() =>
  props.options.find((project) => Number(project.id) === Number(props.modelValue)) || null
)

const filteredOptions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()

  return props.options.filter((project) => {
    if (!q) {
      return true
    }

    return (
      project.title?.toLowerCase().includes(q) ||
      project.student_name?.toLowerCase().includes(q)
    )
  })
})

const openDropdownMenu = () => {
  openDropdown.value = true
  updatePosition()
}

const selectProject = (project) => {
  emit('update:modelValue', project.id)
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
.selected-project {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.project-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem 0.35rem 0.75rem;
  background: rgba(115, 103, 240, 0.1);
  border: 1px solid rgba(115, 103, 240, 0.25);
  border-radius: 999px;
  font-size: 0.82rem;
}

.project-chip__name {
  font-weight: 600;
  color: #5e5873;
}

.project-chip__meta {
  color: #7367f0;
}

.project-chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.25rem;
  line-height: 1;
}

.project-chip__remove:hover {
  color: #ea5455;
}
</style>
