<template>
  <div
    v-if="confirmDialog.state.open"
    class="modal fade show d-block"
    tabindex="-1"
    style="background: rgba(0, 0, 0, 0.45);"
    @click.self="confirmDialog.handleCancel"
  >
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <div class="modal-header">
          <h5 class="modal-title">{{ confirmDialog.state.title }}</h5>
          <button type="button" class="btn-close" @click="confirmDialog.handleCancel"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0">{{ confirmDialog.state.message }}</p>
          <div v-if="confirmDialog.state.prompt" class="mt-3">
            <label class="form-label">{{ confirmDialog.state.promptLabel }}</label>
            <textarea
              v-model="confirmDialog.state.promptValue"
              class="form-control"
              rows="3"
              :required="confirmDialog.state.promptRequired"
            ></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" @click="confirmDialog.handleCancel">
            {{ confirmDialog.state.cancelText }}
          </button>
          <button
            type="button"
            class="btn btn-sm"
            :class="confirmButtonClass"
            @click="confirmDialog.handleConfirm"
          >
            {{ confirmDialog.state.confirmText }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { confirmDialog } from '@/composables/useConfirm'

const confirmButtonClass = computed(() => ({
  primary: 'btn-primary',
  danger: 'btn-danger',
  warning: 'btn-warning',
}[confirmDialog.state.variant] || 'btn-primary'))
</script>
