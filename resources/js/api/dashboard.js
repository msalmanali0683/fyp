import api from './axios'

export const fetchDashboardStats = async () => {
  const { data } = await api.get('/api/dashboard/stats')
  return data
}

export const fetchDashboardCharts = async () => {
  const { data } = await api.get('/api/dashboard/charts')
  return data
}

export const fetchRecentProjects = async () => {
  const { data } = await api.get('/api/dashboard/recent-projects')
  return data
}

export const fetchDashboardNextActions = async () => {
  const { data } = await api.get('/api/dashboard/next-actions')
  return data
}

export const fetchDashboardRoleWidgets = async () => {
  const { data } = await api.get('/api/dashboard/role-widgets')
  return data
}
