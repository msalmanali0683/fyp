import api from './axios'

export const fetchQuestionBank = async (params = {}) => {
  const { data } = await api.get('/api/question-bank', { params })
  return data
}

export const createQuestion = async (payload) => {
  const { data } = await api.post('/api/question-bank', payload)
  return data
}

export const updateQuestion = async (id, payload) => {
  const { data } = await api.put(`/api/question-bank/${id}`, payload)
  return data
}

export const deleteQuestion = async (id) => {
  const { data } = await api.delete(`/api/question-bank/${id}`)
  return data
}
