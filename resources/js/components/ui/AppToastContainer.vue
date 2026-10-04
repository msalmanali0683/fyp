<template>
  <div class="toast-container position-fixed top-0 end-0 p-3 app-toast-container" style="z-index: 1090;">
    <div
      v-for="item in toast.state.toasts"
      :key="item.id"
      class="toast show align-items-center border-0 shadow"
      :class="toastClass(item.variant)"
      role="alert"
      aria-live="assertive"
      aria-atomic="true"
    >
      <div class="d-flex">
        <div class="toast-body">
          <div v-if="item.title" class="fw-semibold mb-1">{{ item.title }}</div>
          <div>{{ item.message }}</div>
        </div>
        <button
          type="button"
          class="btn-close btn-close-white me-2 m-auto"
          aria-label="Close"
          @click="toast.remove(item.id)"
        ></button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { toast } from '@/composables/useToast'

const toastClass = (variant) => ({
  success: 'text-bg-success',
  danger: 'text-bg-danger',
  warning: 'text-bg-warning',
  info: 'text-bg-info',
}[variant] || 'text-bg-primary')
</script>

<style scoped>
.app-toast-container {
  max-width: min(420px, calc(100vw - 1.5rem));
}
</style>
