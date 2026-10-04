import api from './axios'

export const fetchUsers = async (params = {}) => {
  const { data } = await api.get('/api/users', { params })
  return data
}

export const createUser = async (payload) => {
  const { data } = await api.post('/api/users', payload)
  return data
}

export const updateUser = async (id, payload) => {
  const { data } = await api.put(`/api/users/${id}`, payload)
  return data
}

export const deleteUser = async (id) => {
  const { data } = await api.delete(`/api/users/${id}`)
  return data
}

export const updateUserStatus = async (id, status) => {
  const { data } = await api.patch(`/api/users/${id}/status`, { status })
  return data
}
