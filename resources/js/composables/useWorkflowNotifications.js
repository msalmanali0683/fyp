import { useNotificationStore } from '@/stores/notifications'

export function useWorkflowNotifications() {
  const notificationStore = useNotificationStore()

  const refreshNotifications = async () => {
    await notificationStore.loadUnreadCount()
  }

  return { refreshNotifications }
}
