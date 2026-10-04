import api from './axios'

export const fetchEvaluatorOverview = async (params = {}) => {
  const { data } = await api.get('/api/admin/evaluators', { params })
  return data
}

export const updateEvaluatorLimits = async (evaluatorId, payload) => {
  const { data } = await api.patch(`/api/admin/evaluators/${evaluatorId}/evaluation-limits`, payload)
  return data
}
