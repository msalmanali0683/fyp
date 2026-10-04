import api from './axios'

export const fetchAccessiblePrograms = async () => {
  const { data } = await api.get('/api/programs/accessible')
  return data
}

export const fetchDepartments = async () => {
  const { data } = await api.get('/api/departments')
  return data
}

export const createDepartment = async (payload) => {
  const { data } = await api.post('/api/departments', payload)
  return data
}

export const updateDepartment = async (id, payload) => {
  const { data } = await api.put(`/api/departments/${id}`, payload)
  return data
}

export const fetchPrograms = async (params = {}) => {
  const { data } = await api.get('/api/programs', { params: { all: true, ...params } })
  return data
}

export const createProgram = async (payload) => {
  const { data } = await api.post('/api/programs', payload)
  return data
}

export const updateProgram = async (id, payload) => {
  const { data } = await api.put(`/api/programs/${id}`, payload)
  return data
}

export const fetchProgramAccessGrants = async (programId) => {
  const { data } = await api.get(`/api/programs/${programId}/access-grants`)
  return data
}

export const grantProgramAccess = async (programId, granteeUserId) => {
  const { data } = await api.post(`/api/programs/${programId}/access-grants`, {
    grantee_user_id: granteeUserId,
  })
  return data
}

export const revokeProgramAccess = async (programId, grantId) => {
  const { data } = await api.delete(`/api/programs/${programId}/access-grants/${grantId}`)
  return data
}
