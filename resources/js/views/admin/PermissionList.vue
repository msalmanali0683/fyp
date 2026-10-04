<template>
  <div>
    <PageHeader
      title="Permissions"
      subtitle="View all permissions grouped by module and assigned roles"
      breadcrumb="Permissions"
    />

    <div v-if="!permissionGroups.length" class="text-muted">Loading permissions...</div>

    <div v-else class="permission-picker">
      <div class="permission-picker__search mb-3">
        <input
          v-model="searchQuery"
          type="search"
          class="form-control"
          placeholder="Search permissions..."
          autocomplete="off"
        />
      </div>

      <div v-if="!filteredGroups.length" class="text-muted small py-2">
        No permissions match your search.
      </div>

      <div v-else class="permission-picker__groups">
        <div v-for="group in filteredGroups" :key="group.key" class="permission-group-card">
          <div class="permission-group-card__header">
            <span class="permission-group-card__title">{{ group.label }}</span>
            <span class="text-muted small">{{ group.permissions.length }} permissions</span>
          </div>

          <div class="permission-group-card__list">
            <div
              v-for="permission in group.permissions"
              :key="permission.name"
              class="permission-row"
            >
              <span class="permission-row__name">{{ permission.name }}</span>
              <div class="permission-row__roles">
                <AppBadge
                  v-for="role in permission.roles || []"
                  :key="role"
                  variant="primary"
                  class="me-1 mb-1"
                >
                  {{ roleLabel(role) }}
                </AppBadge>
                <span v-if="!(permission.roles || []).length" class="text-muted small">—</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import AppBadge from '@/components/ui/AppBadge.vue'
import { fetchPermissions } from '@/api/permissions'
import { roleLabel } from '@/utils/roles'

const permissions = ref([])
const permissionGroups = ref([])
const searchQuery = ref('')

const permissionMap = computed(() =>
  Object.fromEntries(permissions.value.map((permission) => [permission.name, permission]))
)

const groupedPermissions = computed(() =>
  permissionGroups.value.map((group) => ({
    ...group,
    permissions: (group.permissions || [])
      .map((name) => permissionMap.value[name])
      .filter(Boolean),
  }))
)

const filteredGroups = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  if (!query) {
    return groupedPermissions.value
  }

  return groupedPermissions.value
    .map((group) => ({
      ...group,
      permissions: group.permissions.filter(
        (permission) =>
          permission.name.toLowerCase().includes(query) ||
          group.label.toLowerCase().includes(query) ||
          (permission.roles || []).some((role) => role.toLowerCase().includes(query))
      ),
    }))
    .filter((group) => group.permissions.length > 0)
})

onMounted(async () => {
  const res = await fetchPermissions()
  permissions.value = res.data.permissions || res.data
  permissionGroups.value = res.data.groups || []
})
</script>

<style scoped>
.permission-picker__groups {
  display: flex;
  flex-direction: column;
  gap: 1rem;
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

.permission-group-card__list {
  padding: 0.5rem 1rem 1rem;
}

.permission-row {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) 2fr;
  gap: 1rem;
  align-items: start;
  padding: 0.65rem 0;
  border-bottom: 1px solid #f1f0f5;
}

.permission-row:last-child {
  border-bottom: none;
}

.permission-row__name {
  color: #6e6b7b;
}

@media (max-width: 767.98px) {
  .permission-row {
    grid-template-columns: 1fr;
    gap: 0.35rem;
  }
}
</style>
