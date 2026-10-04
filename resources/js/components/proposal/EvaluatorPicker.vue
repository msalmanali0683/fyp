<template>
  <div class="search-picker evaluator-picker">
    <label v-if="label" class="form-label">{{ label }}</label>

    <div v-if="selectedEvaluators.length" class="selected-evaluators mb-2">
      <span v-for="evaluator in selectedEvaluators" :key="evaluator.id" class="evaluator-chip">
        <span class="evaluator-chip__name">{{ evaluator.name }}</span>
        <span class="evaluator-chip__email">{{ evaluator.email }}</span>
        <button type="button" class="evaluator-chip__remove" aria-label="Remove" @click="removeEvaluator(evaluator.id)">
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
          :disabled="maxReached"
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
              v-for="evaluator in filteredOptions"
              :key="evaluator.id"
              type="button"
              class="search-picker__option"
              @mousedown.prevent="selectEvaluator(evaluator)"
            >
              <span class="search-picker__option-name">{{ evaluator.name }}</span>
              <span class="search-picker__option-meta">{{ evaluator.email }}</span>
            </button>
          </div>

          <div
            v-else-if="searchQuery"
            class="search-picker__dropdown search-picker__dropdown--teleport search-picker__empty"
            :style="dropdownStyle"
          >
            No evaluators found.
          </div>
        </div>
      </Teleport>
    </div>

    <small v-if="hint" class="text-muted d-block mt-1">{{ hint }}</small>
    <small v-if="maxReached" class="text-warning d-block mt-1">Maximum evaluators selected.</small>
    <small v-else-if="modelValue.length < min" class="text-muted d-block mt-1">
      Select at least {{ min }} evaluator{{ min === 1 ? '' : 's' }}.
    </small>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useSearchPickerDropdown } from '@/composables/useSearchPickerDropdown'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  min: { type: Number, default: 2 },
  max: { type: Number, default: 3 },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'Search by name or email...' },
  hint: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')
const openDropdown = ref(false)
const rootRef = ref(null)
const dropdownRef = ref(null)
const { dropdownStyle, updatePosition, containsTarget } = useSearchPickerDropdown(rootRef, openDropdown, dropdownRef)

const selectedEvaluators = computed(() =>
  props.options.filter((evaluator) => props.modelValue.includes(evaluator.id))
)

const maxReached = computed(() => props.modelValue.length >= props.max)

const filteredOptions = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return props.options
    .filter((evaluator) => !props.modelValue.includes(evaluator.id))
    .filter((evaluator) => {
      if (!q) return true
      return evaluator.name?.toLowerCase().includes(q) || evaluator.email?.toLowerCase().includes(q)
    })
})

const openDropdownMenu = () => {
  openDropdown.value = true
  updatePosition()
}

const selectEvaluator = (evaluator) => {
  if (maxReached.value) return
  emit('update:modelValue', [...props.modelValue, evaluator.id])
  searchQuery.value = ''
  openDropdown.value = true
  updatePosition()
}

const removeEvaluator = (id) => {
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
.selected-evaluators {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.evaluator-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem 0.35rem 0.75rem;
  background: rgba(40, 199, 111, 0.12);
  border: 1px solid rgba(40, 199, 111, 0.3);
  border-radius: 999px;
  font-size: 0.82rem;
}

.evaluator-chip__name {
  font-weight: 600;
  color: #5e5873;
}

.evaluator-chip__email {
  color: #28c76f;
}

.evaluator-chip__remove {
  border: 0;
  background: transparent;
  color: #6e6b7b;
  padding: 0 0.25rem;
  line-height: 1;
}

.evaluator-chip__remove:hover {
  color: #ea5455;
}
</style>
