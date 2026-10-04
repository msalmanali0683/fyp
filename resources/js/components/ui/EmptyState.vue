<template>
  <div class="empty-state text-center py-4 px-3">
    <div class="empty-state__icon mb-3">
      <i :class="icon"></i>
    </div>
    <h6 class="mb-2">{{ title }}</h6>
    <p v-if="description" class="text-muted small mb-3">{{ description }}</p>
    <slot />
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  icon: { type: String, default: 'bi bi-inbox' },
})
</script>

<style scoped>
.empty-state__icon {
  width: 3.5rem;
  height: 3.5rem;
  margin: 0 auto;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(115, 103, 240, 0.1);
  color: #7367f0;
  font-size: 1.35rem;
}
</style>
