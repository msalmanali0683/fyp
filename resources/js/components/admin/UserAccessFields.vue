<template>
  <div class="user-access-fields">
    <div class="mb-3">
      <label class="form-label d-block mb-2">Roles</label>
      <div v-if="selectedRoles.length" class="selected-role-badges mb-2">
        <AppBadge
          v-for="role in selectedRoles"
          :key="role"
          variant="primary"
          class="me-1 mb-1"
        >
          {{ roleLabel(role) }}
        </AppBadge>
      </div>
      <div class="row g-2">
        <div v-for="role in displayRoles" :key="role.value" class="col-md-6">
          <div class="form-check">
            <input
              :id="`${idPrefix}-role-${role.value}`"
              class="form-check-input"
              type="checkbox"
              :value="role.value"
              :checked="selectedRoles.includes(role.value)"
              :disabled="!isRoleEditable(role.value)"
              @change="toggleRole(role.value, $event.target.checked)"
            />
            <label class="form-check-label" :for="`${idPrefix}-role-${role.value}`">
              {{ role.label }}
              <span v-if="lockedRoles.includes(role.value)" class="text-muted small">(required)</span>
              <span v-else-if="!isRoleAvailable(role.value)" class="text-muted small">(read only)</span>
            </label>
          </div>
        </div>
      </div>
      <small v-if="selectedRoles.length === 0" class="text-danger">Select at least one role.</small>
    </div>

    <PermissionPicker
      v-if="!hidePermissions"
      v-model="selectedPermissions"
      :groups="permissionGroups"
      title="Direct Permissions"
      description="Assigned directly to this user, in addition to permissions from roles."
      :id-prefix="idPrefix"
    />
    <p v-else class="text-muted small mb-0">
      Student accounts cannot be granted direct permissions.
    </p>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import PermissionPicker from '@/components/admin/PermissionPicker.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import { roleLabel } from '@/utils/roles'

const props = defineProps({
  roles: { type: Array, default: () => [] },
  directPermissions: { type: Array, default: () => [] },
  availableRoles: { type: Array, default: () => [] },
  permissionGroups: { type: Array, default: () => [] },
  lockedRoles: { type: Array, default: () => [] },
  hidePermissions: { type: Boolean, default: false },
  idPrefix: { type: String, default: 'access' },
})

const emit = defineEmits(['update:roles', 'update:directPermissions'])

const selectedRoles = computed({
  get: () => props.roles,
  set: (value) => emit('update:roles', value),
})

const selectedPermissions = computed({
  get: () => props.directPermissions,
  set: (value) => emit('update:directPermissions', value),
})

const displayRoles = computed(() => {
  const roles = new Map(props.availableRoles.map((role) => [role.value, role]))

  props.roles.forEach((roleValue) => {
    if (!roles.has(roleValue)) {
      roles.set(roleValue, {
        value: roleValue,
        label: roleLabel(roleValue),
      })
    }
  })

  return [...roles.values()]
})

const isRoleAvailable = (roleValue) =>
  props.availableRoles.some((role) => role.value === roleValue)

const isRoleEditable = (roleValue) =>
  isRoleAvailable(roleValue) && !props.lockedRoles.includes(roleValue)

const toggleRole = (role, checked) => {
  if (!isRoleEditable(role)) return

  const next = new Set(selectedRoles.value)
  if (checked) next.add(role)
  else next.delete(role)
  lockedRolesEnsure(next)
  selectedRoles.value = [...next]
}

const lockedRolesEnsure = (set) => {
  props.lockedRoles.forEach((role) => set.add(role))
}
</script>

<style scoped>
.selected-role-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
}
</style>
