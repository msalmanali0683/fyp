<template>
  <span
    v-if="help"
    ref="root"
    class="workflow-status-badge"
    :title="`${help.summary} ${help.next}`"
    data-bs-toggle="tooltip"
  >
    <AppBadge :variant="variant">{{ help.label }}</AppBadge>
  </span>
</template>

<script setup>
import { computed, onMounted, onUpdated, ref } from 'vue'
import { Tooltip } from 'bootstrap'
import AppBadge from '@/components/ui/AppBadge.vue'
import { workflowStageHelp } from '@/utils/workflowHelp'

const props = defineProps({
  stage: { type: String, default: '' },
  fallbackLabel: { type: String, default: '' },
})

const root = ref(null)

const help = computed(() => workflowStageHelp(props.stage))

const variant = computed(() => ({
  approved: 'success',
  rejected: 'danger',
  revision_required: 'warning',
  draft: 'secondary',
}[props.stage] || 'info'))

const initTooltips = () => {
  if (!root.value) return
  root.value.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
    Tooltip.getOrCreateInstance(el)
  })
}

onMounted(initTooltips)
onUpdated(initTooltips)
</script>
