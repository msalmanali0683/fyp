import api from './axios'
import { createUser, updateUser, deleteUser } from './users'

export const fetchStudents = async (params = {}) => {
  const { data } = await api.get('/api/users', {
    params: { ...params, only_role: 'student' },
  })
  return data
}

export const createStudent = (payload) =>
  createUser({
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['student'],
  })

export const updateStudent = (id, payload) =>
  updateUser(id, {
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['student'],
  })

export const deleteStudent = (id) => deleteUser(id)
