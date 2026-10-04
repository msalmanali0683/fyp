import api from './axios'

export const fetchRoles = async () => {
  const { data } = await api.get('/api/roles')
  return data
}

export const createRole = async (payload) => {
  const { data } = await api.post('/api/roles', payload)
  return data
}

export const updateRole = async (id, payload) => {
  const { data } = await api.put(`/api/roles/${id}`, payload)
  return data
}

export const deleteRole = async (id) => {
  const { data } = await api.delete(`/api/roles/${id}`)
  return data
}
