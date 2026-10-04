import { onBeforeUnmount, ref, watch } from 'vue'

export function useSearchPickerDropdown(rootRef, openDropdown, dropdownRef = null) {
  const dropdownStyle = ref({})

  const updatePosition = () => {
    const el = rootRef.value
    if (!el) {
      return
    }

    const rect = el.getBoundingClientRect()
    dropdownStyle.value = {
      top: `${rect.bottom + 4}px`,
      left: `${rect.left}px`,
      width: `${rect.width}px`,
    }
  }

  const scrollOptions = { capture: true }

  const attachListeners = () => {
    updatePosition()
    window.addEventListener('scroll', updatePosition, scrollOptions)
    window.addEventListener('resize', updatePosition)
  }

  const detachListeners = () => {
    window.removeEventListener('scroll', updatePosition, scrollOptions)
    window.removeEventListener('resize', updatePosition)
  }

  watch(openDropdown, (open) => {
    if (open) {
      attachListeners()
    } else {
      detachListeners()
    }
  })

  onBeforeUnmount(detachListeners)

  const containsTarget = (target) => {
    if (rootRef.value?.contains(target)) {
      return true
    }

    if (dropdownRef?.value?.contains(target)) {
      return true
    }

    return false
  }

  return { dropdownStyle, updatePosition, containsTarget }
}
