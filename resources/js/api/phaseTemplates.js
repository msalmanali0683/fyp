import api from './axios'

export const fetchPhaseTemplates = async (params = {}) => {
  const { data } = await api.get('/api/phase-templates', { params })
  return data
}

export const uploadPhaseTemplate = async (payload) => {
  const formData = new FormData()
  formData.append('phase', payload.phase)
  formData.append('file', payload.file)
  if (payload.title) formData.append('title', payload.title)
  if (payload.description) formData.append('description', payload.description)

  const { data } = await api.post('/api/phase-templates', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export const deletePhaseTemplate = async (id) => {
  const { data } = await api.delete(`/api/phase-templates/${id}`)
  return data
}
