import { reactive } from 'vue'

const state = reactive({
  open: false,
  title: 'Confirm',
  message: '',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  variant: 'primary',
  prompt: false,
  promptLabel: 'Reason (optional)',
  promptValue: '',
  promptRequired: false,
})

let resolver = null

export function useConfirm() {
  const close = () => {
    state.open = false
    state.promptValue = ''
    resolver = null
  }

  const confirm = (options = {}) =>
    new Promise((resolve) => {
      resolver = resolve
      state.title = options.title || 'Confirm'
      state.message = options.message || 'Are you sure?'
      state.confirmText = options.confirmText || 'Confirm'
      state.cancelText = options.cancelText || 'Cancel'
      state.variant = options.variant || 'primary'
      state.prompt = !!options.prompt
      state.promptLabel = options.promptLabel || 'Comments'
      state.promptValue = options.promptValue || ''
      state.promptRequired = !!options.promptRequired
      state.open = true
    })

  const handleConfirm = () => {
    if (state.promptRequired && !state.promptValue.trim()) {
      return
    }

    const value = state.prompt ? state.promptValue.trim() : true
    resolver?.(value)
    close()
  }

  const handleCancel = () => {
    resolver?.(false)
    close()
  }

  return {
    state,
    confirm,
    handleConfirm,
    handleCancel,
  }
}

export const confirmDialog = useConfirm()
