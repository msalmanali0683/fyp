<template>
  <div class="vuexy-card" :class="{ 'vuexy-card--overflow-visible': overflowVisible }">
    <div v-if="title || $slots.header" class="card-header">
      <div>
        <h5 v-if="title" class="card-title">{{ title }}</h5>
        <p v-if="subtitle" class="card-subtitle">{{ subtitle }}</p>
      </div>
      <slot name="header" />
    </div>
    <div class="card-body" :class="bodyClass">
      <slot />
    </div>
    <div v-if="$slots.footer" class="card-footer bg-transparent border-top-0 pt-0 px-4 pb-4">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  bodyClass: { type: String, default: '' },
  overflowVisible: { type: Boolean, default: false },
})
</script>
