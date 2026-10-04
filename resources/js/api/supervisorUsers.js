import api from './axios'
import { createUser, updateUser, deleteUser } from './users'

export const fetchSupervisorUsers = async (params = {}) => {
  const { data } = await api.get('/api/users', {
    params: { ...params, only_role: 'supervisor' },
  })
  return data
}

export const createSupervisorUser = (payload) =>
  createUser({
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['faculty', 'supervisor'],
  })

export const updateSupervisorUser = (id, payload) =>
  updateUser(id, {
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['faculty', 'supervisor'],
  })

export const deleteSupervisorUser = (id) => deleteUser(id)
