import api from './axios'

export const fetchProposalSessions = async () => {
  const { data } = await api.get('/api/proposal-sessions')
  return data
}

export const fetchProposalSession = async (sessionId) => {
  const { data } = await api.get(`/api/proposal-sessions/${sessionId}`)
  return data
}

export const fetchProposalSessionOptions = async (params = {}) => {
  const { data } = await api.get('/api/proposal-sessions/options', { params })
  return data
}

export const fetchCurrentProposalSession = async (programId) => {
  const { data } = await api.get('/api/proposal-sessions/current', { params: { program_id: programId } })
  return data
}

export const fetchProposalSessionStudents = async (sessionId, params = {}) => {
  const { data } = await api.get(`/api/proposal-sessions/${sessionId}/students`, { params })
  return data
}

export const exportProposalSessionStudents = async (sessionId, sessionCode = 'session') => {
  const response = await api.get(`/api/proposal-sessions/${sessionId}/students/export`, {
    responseType: 'blob',
  })

  const blob = new Blob([response.data], { type: 'text/csv' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${String(sessionCode).toLowerCase()}-students-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const downloadProposalSessionStudentsTemplate = async (sessionId, sessionCode = 'session') => {
  const response = await api.get(`/api/proposal-sessions/${sessionId}/students/import/template`, {
    responseType: 'blob',
  })

  const blob = new Blob([response.data], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${String(sessionCode).toLowerCase()}-students-import-template.xlsx`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const importProposalSessionStudents = async (sessionId, file) => {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/students/import`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return data
}

export const createProposalSession = async (payload) => {
  const { data } = await api.post('/api/proposal-sessions', payload)
  return data
}

export const updateProposalSession = async (sessionId, payload) => {
  const { data } = await api.put(`/api/proposal-sessions/${sessionId}`, payload)
  return data
}

export const openProposalSession = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/open-submissions`)
  return data
}

export const closeProposalSession = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/close-submissions`)
  return data
}

export const lockProposalSession = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/lock-all`)
  return data
}

export const unlockProposalSession = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/unlock-all`)
  return data
}

export const extendProposalSessionDeadlines = async (sessionId, payload) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/extend-deadlines`, payload)
  return data
}

export const previewCompleteProposalPhase = async (sessionId) => {
  const { data } = await api.get(`/api/proposal-sessions/${sessionId}/complete-proposal-phase/preview`)
  return data
}

export const completeProposalPhase = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/complete-proposal-phase`)
  return data
}

export const completePhase1 = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/complete-phase-1`)
  return data
}

export const completePhase2 = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/complete-phase-2`)
  return data
}

export const generateProposalSessionReports = async (sessionId) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/generate-reports`)
  return data
}

export const downloadProposalSessionReport = async (sessionId, reportId, fileName) => {
  const response = await api.get(`/api/proposal-sessions/${sessionId}/reports/${reportId}/download`, {
    responseType: 'blob',
  })
  const url = window.URL.createObjectURL(new Blob([response.data]))
  const link = document.createElement('a')
  link.href = url
  link.setAttribute('download', fileName)
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const grantProposalSessionExtension = async (sessionId, payload) => {
  const { data } = await api.post(`/api/proposal-sessions/${sessionId}/extensions`, payload)
  return data
}

export const revokeProposalSessionExtension = async (sessionId, userId) => {
  const { data } = await api.delete(`/api/proposal-sessions/${sessionId}/extensions/${userId}`)
  return data
}

export const fetchProposalSessionContext = async () => {
  const { data } = await api.get('/api/proposals/session-context')
  return data
}
