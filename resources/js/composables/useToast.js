import { reactive } from 'vue'

const state = reactive({
  toasts: [],
})

let nextId = 1

export function useToast() {
  const remove = (id) => {
    const index = state.toasts.findIndex((toast) => toast.id === id)
    if (index >= 0) {
      state.toasts.splice(index, 1)
    }
  }

  const show = (message, options = {}) => {
    const id = nextId++
    const toast = {
      id,
      message,
      title: options.title || '',
      variant: options.variant || 'success',
      delay: options.delay ?? 4000,
    }

    state.toasts.push(toast)

    if (toast.delay > 0) {
      window.setTimeout(() => remove(id), toast.delay)
    }

    return id
  }

  return {
    state,
    show,
    success: (message, options = {}) => show(message, { ...options, variant: 'success' }),
    error: (message, options = {}) => show(message, { ...options, variant: 'danger' }),
    warning: (message, options = {}) => show(message, { ...options, variant: 'warning' }),
    info: (message, options = {}) => show(message, { ...options, variant: 'info' }),
    remove,
  }
}

export const toast = useToast()
