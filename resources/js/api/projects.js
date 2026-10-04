import api from './axios'

export const fetchProjects = async (params = {}) => {
  const { data } = await api.get('/api/projects', { params })
  return data
}

export const exportProjects = async (params = {}) => {
  const response = await api.get('/api/projects/export', {
    params,
    responseType: 'blob',
  })

  const blob = new Blob([response.data], { type: 'text/csv' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `fyp-projects-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const fetchProject = async (id) => {
  const { data } = await api.get(`/api/projects/${id}`)
  return data
}

export const createProject = async (payload) => {
  const { data } = await api.post('/api/projects', payload)
  return data
}

export const updateProject = async (id, payload) => {
  const { data } = await api.put(`/api/projects/${id}`, payload)
  return data
}

export const deleteProject = async (id, payload = {}) => {
  const { data } = await api.delete(`/api/projects/${id}`, { data: payload })
  return data
}

export const forceDeleteProject = async (id) => {
  const { data } = await api.delete(`/api/projects/${id}/force`)
  return data
}

export const removeProjectMember = async (projectId, memberId, payload = {}) => {
  const { data } = await api.delete(`/api/projects/${projectId}/members/${memberId}`, { data: payload })
  return data
}

export const fetchSupervisors = async (params = {}) => {
  const { data } = await api.get('/api/projects/supervisors', { params })
  return data
}

export const transferProjectLeadership = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/transfer-leadership`, payload)
  return data
}

export const committeeHeadApproveAll = async () => {
  const { data } = await api.post('/api/projects/committee-head/approve-all')
  return data
}

export const updatePhase = async (projectId, phase, payload) => {
  const { data } = await api.put(`/api/projects/${projectId}/phases/${phase}`, payload)
  return data
}

export const uploadPhaseAttachment = async (projectId, phase, file) => {
  const formData = new FormData()
  formData.append('file', file)
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/attachment`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export const phaseSupervisorRespond = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/supervisor/respond`, payload)
  return data
}

export const phaseSupervisorRevisionReview = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/supervisor/revision-review`, payload)
  return data
}

export const phaseKeepEvaluators = async (projectId, phase) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/evaluators/keep`)
  return data
}

export const phaseAssignEvaluators = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/evaluators`, payload)
  return data
}

export const phaseReturnFromCommittee = async (projectId, phase, payload = {}) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/committee-return`, payload)
  return data
}

export const phaseEvaluatorReview = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/evaluator-review`, payload)
  return data
}

export const phaseResubmitDeliverable = async (projectId, phase) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/resubmit`)
  return data
}

export const phaseCommitteeFinalReview = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/committee-final`, payload)
  return data
}

export const phaseCommitteeHeadApprove = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/committee-head-approve`, payload)
  return data
}

export const phaseReevaluate = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/reevaluate`, payload)
  return data
}

export const phaseAllowRepeatCarryForward = async (projectId, phase, payload = {}) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/repeat/allow`, payload)
  return data
}

export const phaseRequireRepeatResubmission = async (projectId, phase, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/repeat/resubmit`, payload)
  return data
}

export const submitPhase = async (projectId, phase) => {
  const { data } = await api.post(`/api/projects/${projectId}/phases/${phase}/submit`)
  return data
}

export const reviewPhase = async (projectId, phase, payload) => {
  const { data } = await api.patch(`/api/projects/${projectId}/phases/${phase}/review`, payload)
  return data
}

export const downloadPhaseCertificate = async (projectId, phase, fileName) => {
  const response = await api.get(`/api/projects/${projectId}/phases/${phase}/certificate`, {
    responseType: 'blob',
  })

  const blob = new Blob([response.data], { type: 'application/pdf' })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName || `${phase}-certificate.pdf`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
