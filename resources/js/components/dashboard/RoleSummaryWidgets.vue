<template>
  <div v-if="widgets.length" class="row g-3 mb-3">
    <div v-for="widget in widgets" :key="widget.key" class="col-12 col-md-4">
      <div class="role-widget">
        <div class="role-widget__label">{{ widget.label }}</div>
        <div class="role-widget__value">{{ widget.value }}</div>
        <div v-if="widget.hint" class="role-widget__hint">{{ widget.hint }}</div>
        <router-link v-if="widget.route" :to="widgetRoute(widget.route)" class="role-widget__link small">
          {{ widget.link_label || 'View details' }} <i class="bi bi-arrow-right"></i>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  widgets: { type: Array, default: () => [] },
})

const widgetRoute = (route) =>
  typeof route === 'string' ? { name: route } : route
</script>

<style scoped>
.role-widget {
  background: #fff;
  border: 1px solid #ebe9f1;
  border-radius: 0.5rem;
  padding: 1rem 1.1rem;
  height: 100%;
}

.role-widget__label {
  color: #6e6b7b;
  font-size: 0.82rem;
}

.role-widget__value {
  font-size: 1.45rem;
  font-weight: 700;
  color: #5e5873;
  margin: 0.25rem 0;
}

.role-widget__hint {
  color: #6e6b7b;
  font-size: 0.82rem;
}

.role-widget__link {
  display: inline-block;
  margin-top: 0.5rem;
  text-decoration: none;
}
</style>
