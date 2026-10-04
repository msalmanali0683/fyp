import api from './axios'

export const fetchMyQueryableProjects = async () => {
  const { data } = await api.get('/api/queries/my-projects')
  return data
}

export const fetchProjectQueries = async (params = {}) => {
  const { data } = await api.get('/api/queries', { params })
  return data
}

export const fetchProjectQuery = async (id) => {
  const { data } = await api.get(`/api/queries/${id}`)
  return data
}

export const createProjectQuery = async (payload) => {
  const { data } = await api.post('/api/queries', payload)
  return data
}

export const replyToProjectQuery = async (id, payload) => {
  const { data } = await api.post(`/api/queries/${id}/reply`, payload)
  return data
}

export const closeProjectQuery = async (id) => {
  const { data } = await api.post(`/api/queries/${id}/close`)
  return data
}

export const reopenProjectQuery = async (id) => {
  const { data } = await api.post(`/api/queries/${id}/reopen`)
  return data
}

export const downloadProjectQueryPdf = async (id, fileName) => {
  const response = await api.get(`/api/queries/${id}/pdf`, { responseType: 'blob' })

  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName || `query-${id}-thread.pdf`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
