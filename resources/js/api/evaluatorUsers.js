import api from './axios'
import { createUser, updateUser, deleteUser } from './users'

export const fetchEvaluatorUsers = async (params = {}) => {
  const { data } = await api.get('/api/users', {
    params: { ...params, only_role: 'evaluator' },
  })
  return data
}

export const createEvaluatorUser = (payload) =>
  createUser({
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['faculty', 'evaluator'],
  })

export const updateEvaluatorUser = (id, payload) =>
  updateUser(id, {
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['faculty', 'evaluator'],
  })

export const deleteEvaluatorUser = (id) => deleteUser(id)
