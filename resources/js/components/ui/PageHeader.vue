<template>
  <div class="page-header">
    <nav aria-label="breadcrumb">
      <div class="breadcrumb-bar">
        <router-link to="/">Home</router-link>
        <template v-for="(crumb, index) in breadcrumbItems" :key="`${crumb.label}-${index}`">
          <span class="separator">/</span>
          <router-link v-if="crumb.to" :to="crumb.to">{{ crumb.label }}</router-link>
          <span v-else class="active">{{ crumb.label }}</span>
        </template>
      </div>
    </nav>
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
      <div>
        <h4>{{ title }}</h4>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
      <div v-if="$slots.actions" class="d-flex flex-wrap gap-2 page-header__actions">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  breadcrumb: { type: String, default: 'Dashboard' },
  breadcrumbTrail: { type: Array, default: null },
})

const route = useRoute()

const breadcrumbItems = computed(() => {
  if (props.breadcrumbTrail?.length) {
    return props.breadcrumbTrail
  }

  if (route.meta.breadcrumbTrail?.length) {
    return route.meta.breadcrumbTrail
  }

  return [{ label: props.breadcrumb }]
})
</script>
