import api from './axios'

export const fetchEvaluatorAssignmentSessions = async () => {
  const { data } = await api.get('/api/evaluator-assignment/sessions')
  return data
}

export const fetchPendingEvaluatorAssignments = async (params = {}) => {
  const { data } = await api.get('/api/evaluator-assignment/pending', { params })
  return data
}

export const previewAutoEvaluatorAssignment = async (payload) => {
  const { data } = await api.post('/api/evaluator-assignment/preview', payload)
  return data
}

export const runAutoEvaluatorAssignment = async (payload) => {
  const { data } = await api.post('/api/evaluator-assignment/auto-assign', payload)
  return data
}
