import api from './axios'

export const fetchSupervisorOverview = async (params = {}) => {
  const { data } = await api.get('/api/admin/supervisors', { params })
  return data
}

export const fetchSupervisorProfile = async (supervisorId, params = {}) => {
  const { data } = await api.get(`/api/admin/supervisors/${supervisorId}`, { params })
  return data
}

export const fetchSupervisorChangeRequests = async (params = {}) => {
  const { data } = await api.get('/api/supervisor-change-requests', { params })
  return data
}
