<template>
  <div class="brand-badge" :class="`brand-badge--${size}`" :title="label">
    <img
      v-if="src && !failed"
      :src="src"
      :alt="label"
      class="brand-badge__image"
      @error="failed = true"
    />
    <span v-else class="brand-badge__fallback">{{ fallback }}</span>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  src: { type: String, default: '' },
  fallback: { type: String, required: true },
  label: { type: String, default: '' },
  size: { type: String, default: 'md' },
})

const failed = ref(false)

watch(() => props.src, () => {
  failed.value = false
})
</script>

<style scoped>
.brand-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.5rem;
  overflow: hidden;
  flex-shrink: 0;
  background: #fff;
  box-shadow: 0 8px 20px -10px rgba(0, 0, 0, 0.45);
}

.brand-badge--md {
  height: 52px;
}

.brand-badge--sm {
  height: 38px;
}

.brand-badge__image {
  height: 100%;
  width: auto;
  max-width: 160px;
  object-fit: contain;
  display: block;
}

/* Fallback badge (shown only if the image file is missing) */
.brand-badge:has(.brand-badge__fallback) {
  background: rgba(255, 255, 255, 0.14);
  backdrop-filter: blur(6px);
  border: 1px solid rgba(255, 255, 255, 0.18);
  padding: 0 0.85rem;
}

.brand-badge__fallback {
  font-weight: 700;
  font-size: 0.7rem;
  letter-spacing: 0.03em;
  color: #fff;
}

.brand-badge--sm .brand-badge__fallback {
  font-size: 0.6rem;
}
</style>
