<template>
  <div class="permission-picker">
    <div v-if="title" class="permission-picker__heading">{{ title }}</div>
    <p v-if="description" class="permission-picker__description text-muted">{{ description }}</p>

    <div v-if="showSearch" class="permission-picker__search mb-3">
      <input
        v-model="searchQuery"
        type="search"
        class="form-control"
        placeholder="Search permissions..."
        autocomplete="off"
      />
    </div>

    <div v-if="!groups.length" class="text-muted small">Loading permissions...</div>

    <div v-else-if="!filteredGroups.length" class="text-muted small py-2">
      No permissions match your search.
    </div>

    <div v-else class="permission-picker__groups">
      <div v-for="group in filteredGroups" :key="group.key" class="permission-group-card">
        <div class="permission-group-card__header">
          <span class="permission-group-card__title">{{ group.label }}</span>
          <div class="permission-group-card__actions">
            <button type="button" class="btn btn-link btn-sm p-0" @click="selectAllInGroup(group)">
              Select all
            </button>
            <span class="text-muted mx-1">|</span>
            <button type="button" class="btn btn-link btn-sm p-0" @click="clearGroup(group)">
              Clear
            </button>
          </div>
        </div>

        <div class="permission-group-card__body">
          <div
            v-for="permission in group.permissions"
            :key="permission"
            class="form-check permission-check"
          >
            <input
              :id="`${idPrefix}-perm-${permission}`"
              class="form-check-input"
              type="checkbox"
              :value="permission"
              :checked="modelValue.includes(permission)"
              @change="togglePermission(permission, $event.target.checked)"
            />
            <label class="form-check-label" :for="`${idPrefix}-perm-${permission}`">
              {{ permission }}
            </label>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  groups: { type: Array, default: () => [] },
  title: { type: String, default: 'Direct Permissions' },
  description: { type: String, default: '' },
  showSearch: { type: Boolean, default: true },
  idPrefix: { type: String, default: 'perm' },
})

const emit = defineEmits(['update:modelValue'])

const searchQuery = ref('')

const filteredGroups = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  if (!query) {
    return props.groups
  }

  return props.groups
    .map((group) => ({
      ...group,
      permissions: (group.permissions || []).filter((permission) =>
        permission.toLowerCase().includes(query) ||
        group.label.toLowerCase().includes(query)
      ),
    }))
    .filter((group) => group.permissions.length > 0)
})

const togglePermission = (permission, checked) => {
  const next = new Set(props.modelValue)
  if (checked) next.add(permission)
  else next.delete(permission)
  emit('update:modelValue', [...next])
}

const selectAllInGroup = (group) => {
  const next = new Set(props.modelValue)
  ;(group.permissions || []).forEach((permission) => next.add(permission))
  emit('update:modelValue', [...next])
}

const clearGroup = (group) => {
  const remove = new Set(group.permissions || [])
  emit(
    'update:modelValue',
    props.modelValue.filter((permission) => !remove.has(permission))
  )
}
</script>

<style scoped>
.permission-picker__heading {
  font-weight: 600;
  margin-bottom: 0.75rem;
}

.permission-picker__description {
  font-size: 0.875rem;
  margin-bottom: 0.75rem;
}

.permission-picker__groups {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-height: 420px;
  overflow-y: auto;
  padding-right: 0.25rem;
}

.permission-group-card {
  border: 1px solid #dbdade;
  border-radius: 0.375rem;
  background: #fff;
}

.permission-group-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #ebe9f1;
}

.permission-group-card__title {
  font-weight: 600;
  color: #5d596c;
}

.permission-group-card__actions .btn-link {
  color: #00cfe8;
  text-decoration: none;
  font-size: 0.8125rem;
}

.permission-group-card__actions .btn-link:hover {
  color: #00a8bd;
}

.permission-group-card__body {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.5rem 1.25rem;
  padding: 1rem;
}

@media (max-width: 575.98px) {
  .permission-group-card__body {
    grid-template-columns: 1fr;
  }
}

.permission-check .form-check-input:checked {
  background-color: #7367f0;
  border-color: #7367f0;
}

.permission-check .form-check-label {
  font-size: 0.9375rem;
  color: #6e6b7b;
}
</style>
