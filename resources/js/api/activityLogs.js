import api from './axios'

export const fetchActivityLogs = async (params = {}) => {
  const { data } = await api.get('/api/admin/activity-logs', { params })
  return data
}
