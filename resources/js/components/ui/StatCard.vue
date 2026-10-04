<template>
  <component :is="to ? 'router-link' : 'div'" :to="to" class="stat-card-link">
    <div class="stat-card">
    <div class="card-body">
      <div class="stat-content">
        <div class="stat-label">{{ label }}</div>
        <div class="stat-value">{{ value }}</div>
        <div v-if="change !== null" class="stat-change" :class="changeType">
          <i :class="changeType === 'up' ? 'bi bi-arrow-up-short' : 'bi bi-arrow-down-short'"></i>
          {{ Math.abs(change) }}%
          <span class="text-muted ms-1">{{ changeLabel }}</span>
        </div>
      </div>
      <div class="stat-icon" :class="variant">
        <i :class="icon"></i>
      </div>
    </div>
    </div>
  </component>
</template>

<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  icon: { type: String, default: 'bi bi-graph-up' },
  to: { type: [String, Object], default: null },
  variant: {
    type: String,
    default: 'primary',
    validator: (v) => ['primary', 'success', 'warning', 'info', 'danger'].includes(v),
  },
  change: { type: Number, default: null },
  changeType: {
    type: String,
    default: 'up',
    validator: (v) => ['up', 'down'].includes(v),
  },
  changeLabel: { type: String, default: 'vs last month' },
})
</script>

<style scoped>
.stat-card-link {
  display: block;
  text-decoration: none;
  color: inherit;
}

.stat-card-link:hover .stat-card {
  border-color: #7367f0;
}
</style>
