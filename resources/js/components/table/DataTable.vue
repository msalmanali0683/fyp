<template>
  <AppCard :title="title" :subtitle="subtitle">
    <template v-if="$slots.header" #header>
      <slot name="header" />
    </template>

    <div class="table-responsive-wrapper">
      <table class="vuexy-table" :class="{ 'mobile-card-view': mobileCardView }">
        <thead>
          <tr>
            <th v-for="col in columns" :key="col.key">{{ col.label }}</th>
            <th v-if="$slots.actions" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!rows.length">
            <td :colspan="columns.length + ($slots.actions ? 1 : 0)">
              <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p class="mb-0">No records found</p>
              </div>
            </td>
          </tr>
          <tr v-for="(row, index) in rows" :key="row.id ?? index">
            <td
              v-for="col in columns"
              :key="col.key"
              :data-label="col.label"
            >
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                {{ row[col.key] }}
              </slot>
            </td>
            <td v-if="$slots.actions" data-label="Actions" class="text-end">
              <slot name="actions" :row="row" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppCard>
</template>

<script setup>
import AppCard from '@/components/ui/AppCard.vue'

defineProps({
  title: { type: String, default: 'Data Table' },
  subtitle: { type: String, default: '' },
  columns: { type: Array, required: true },
  rows: { type: Array, default: () => [] },
  mobileCardView: { type: Boolean, default: true },
})
</script>
