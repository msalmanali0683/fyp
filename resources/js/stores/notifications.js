import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  fetchNotifications,
  fetchUnreadNotificationCount,
  markAllNotificationsRead,
  markNotificationRead,
} from '@/api/notifications'

export const useNotificationStore = defineStore('notifications', () => {
  const items = ref([])
  const unreadCount = ref(0)
  const loading = ref(false)
  const open = ref(false)

  const loadUnreadCount = async () => {
    try {
      const res = await fetchUnreadNotificationCount()
      unreadCount.value = res.data?.unread_count ?? 0
    } catch {
      unreadCount.value = 0
    }
  }

  const loadNotifications = async () => {
    loading.value = true
    try {
      const res = await fetchNotifications({ per_page: 10 })
      items.value = res.data?.notifications || []
    } catch {
      items.value = []
    } finally {
      loading.value = false
    }
  }

  const toggleOpen = async () => {
    open.value = !open.value
    if (open.value) {
      await loadNotifications()
    }
  }

  const markRead = async (notification) => {
    if (!notification?.id || notification.is_read) {
      return
    }

    await markNotificationRead(notification.id)
    notification.is_read = true
    notification.read_at = new Date().toISOString()
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  }

  const markAllRead = async () => {
    await markAllNotificationsRead()
    items.value = items.value.map((item) => ({ ...item, is_read: true }))
    unreadCount.value = 0
  }

  const init = async () => {
    await loadUnreadCount()
  }

  return {
    items,
    unreadCount,
    loading,
    open,
    init,
    loadUnreadCount,
    loadNotifications,
    toggleOpen,
    markRead,
    markAllRead,
  }
})
