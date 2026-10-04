import api from './axios'

export const fetchNotifications = async (params = {}) => {
  const { data } = await api.get('/api/notifications', { params })
  return data
}

export const fetchUnreadNotificationCount = async () => {
  const { data } = await api.get('/api/notifications/unread-count')
  return data
}

export const markNotificationRead = async (id) => {
  const { data } = await api.patch(`/api/notifications/${id}/read`)
  return data
}

export const markAllNotificationsRead = async () => {
  const { data } = await api.patch('/api/notifications/read-all')
  return data
}

export const deleteNotification = async (id) => {
  const { data } = await api.delete(`/api/notifications/${id}`)
  return data
}

export const fetchNotificationRecipients = async (params = {}) => {
  const { data } = await api.get('/api/notifications/broadcast/recipients', { params })
  return data
}

export const broadcastNotification = async (payload) => {
  const { data } = await api.post('/api/notifications/broadcast', payload)
  return data
}
