<template>
  <span class="app-badge" :class="badgeClass">
    <slot>{{ label }}</slot>
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, default: '' },
  variant: {
    type: String,
    default: 'primary',
    validator: (v) => ['primary', 'success', 'warning', 'danger', 'info', 'secondary'].includes(v),
  },
  light: { type: Boolean, default: true },
})

const badgeClass = computed(() => [
  `app-badge--${props.variant}`,
  props.light ? 'app-badge--light' : 'app-badge--solid',
])
</script>

<style scoped>
.app-badge {
  display: inline-block;
  padding: 0.35em 0.75em;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1;
  border-radius: 50rem;
  white-space: nowrap;
  vertical-align: baseline;
}

/* Light badges: tinted background + readable colored text */
.app-badge--light.app-badge--primary {
  background-color: rgba(115, 103, 240, 0.14);
  color: #5e50ee;
}

.app-badge--light.app-badge--success {
  background-color: rgba(40, 199, 111, 0.14);
  color: #1f9d57;
}

.app-badge--light.app-badge--warning {
  background-color: rgba(255, 159, 67, 0.16);
  color: #c56a00;
}

.app-badge--light.app-badge--danger {
  background-color: rgba(234, 84, 85, 0.14);
  color: #c62828;
}

.app-badge--light.app-badge--info {
  background-color: rgba(0, 207, 232, 0.14);
  color: #0097a7;
}

.app-badge--light.app-badge--secondary {
  background-color: rgba(105, 122, 141, 0.12);
  color: #566a7f;
}

/* Solid badges: strong background + white text */
.app-badge--solid.app-badge--primary {
  background-color: #7367f0;
  color: #fff;
}

.app-badge--solid.app-badge--success {
  background-color: #28c76f;
  color: #fff;
}

.app-badge--solid.app-badge--warning {
  background-color: #ff9f43;
  color: #fff;
}

.app-badge--solid.app-badge--danger {
  background-color: #ea5455;
  color: #fff;
}

.app-badge--solid.app-badge--info {
  background-color: #00cfe8;
  color: #fff;
}

.app-badge--solid.app-badge--secondary {
  background-color: #8592a3;
  color: #fff;
}
</style>
