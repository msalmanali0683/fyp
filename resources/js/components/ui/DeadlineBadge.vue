<template>
  <AppBadge v-if="label" :variant="variant" class="deadline-badge">
    <i class="bi bi-clock me-1"></i>{{ label }}
  </AppBadge>
</template>

<script setup>
import { computed } from 'vue'
import AppBadge from '@/components/ui/AppBadge.vue'

const props = defineProps({
  deadline: { type: String, default: '' },
  countdown: { type: Object, default: null },
})

const label = computed(() => {
  if (props.countdown?.label) {
    return props.countdown.label
  }

  if (!props.deadline) {
    return ''
  }

  const target = new Date(props.deadline)
  if (Number.isNaN(target.getTime())) {
    return ''
  }

  const diffMs = target.getTime() - Date.now()
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24))

  if (diffDays < 0) {
    return `Overdue by ${Math.abs(diffDays)} day${Math.abs(diffDays) === 1 ? '' : 's'}`
  }

  if (diffDays === 0) {
    return 'Due today'
  }

  return `${diffDays} day${diffDays === 1 ? '' : 's'} left`
})

const variant = computed(() => {
  const text = label.value
  if (!text) return 'secondary'
  if (text.startsWith('Overdue')) return 'danger'
  if (text === 'Due today' || text.includes('1 day')) return 'warning'
  return 'info'
})
</script>
