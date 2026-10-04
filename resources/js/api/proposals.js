import api from './axios'

export const fetchProposalSettings = async () => {
  const { data } = await api.get('/api/proposals/settings')
  return data
}

export const fetchMyProject = async () => {
  const { data } = await api.get('/api/proposals/my-project')
  return data
}

export const fetchEligibleMembers = async (params = {}) => {
  const { data } = await api.get('/api/proposals/eligible-members', { params })
  return data
}

export const inviteProjectMembers = async (projectId, inviteeIds) => {
  const { data } = await api.post(`/api/projects/${projectId}/invitations`, { invitee_ids: inviteeIds })
  return data
}

export const cancelProjectInvitation = async (projectId, invitationId, payload = {}) => {
  const { data } = await api.delete(`/api/projects/${projectId}/invitations/${invitationId}`, { data: payload })
  return data
}

export const acceptProjectInvitationOnBehalf = async (projectId, invitationId, payload = {}) => {
  const { data } = await api.post(`/api/projects/${projectId}/invitations/${invitationId}/accept-on-behalf`, payload)
  return data
}

export const addTeamMembersDirectly = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/members/direct`, payload)
  return data
}

export const updateProposalSettings = async (payload) => {
  const { data } = await api.put('/api/proposals/settings', payload)
  return data
}

export const updateSupervisorLimits = async (userId, payload) => {
  const { data } = await api.patch(`/api/admin/supervisors/${userId}/supervision-limits`, payload)
  return data
}

export const fetchPendingInvitations = async () => {
  const { data } = await api.get('/api/proposals/pending-invitations')
  return data
}

export const submitProposal = async (payload) => {
  const { data } = await api.post('/api/proposals/submit', payload, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export const respondInvitation = async (invitationId, payload) => {
  const { data } = await api.post(`/api/proposals/invitations/${invitationId}/respond`, payload)
  return data
}

export const supervisorRespond = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/supervisor/respond`, payload)
  return data
}

export const supervisorRequestRevision = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/supervisor/request-revision`, payload)
  return data
}

export const supervisorRevisionReview = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/supervisor/revision-review`, payload)
  return data
}

export const changeSupervisor = async (projectId, supervisorId) => {
  const { data } = await api.put(`/api/projects/${projectId}/supervisor`, { supervisor_id: supervisorId })
  return data
}

export const submitSupervisorChangeRequest = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/supervisor-change`, payload)
  return data
}

export const assignEvaluators = async (projectId, payload) => {
  const body = Array.isArray(payload)
    ? { evaluator_ids: payload }
    : payload
  const { data } = await api.post(`/api/projects/${projectId}/evaluators`, body)
  return data
}

export const submitEvaluatorReview = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/evaluator-review`, payload)
  return data
}

export const resubmitProposal = async (projectId, formData) => {
  const { data } = await api.post(`/api/projects/${projectId}/resubmit`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export const committeeFinalReview = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/committee-final`, payload)
  return data
}

export const committeeHeadApprove = async (projectId, payload) => {
  const { data } = await api.post(`/api/projects/${projectId}/committee-head-approve`, payload)
  return data
}

export const returnToTeamFormation = async (projectId, payload = {}) => {
  const { data } = await api.post(`/api/projects/${projectId}/return-to-team-formation`, payload)
  return data
}

export const fetchEvaluators = async (params = {}) => {
  const { data } = await api.get('/api/proposals/evaluators', { params })
  return data
}

export const respondSupervisorChangeRequest = async (projectId, changeRequestId, payload) => {
  const { data } = await api.post(
    `/api/projects/${projectId}/supervisor-change/${changeRequestId}/respond`,
    payload
  )
  return data
}

export const decideSupervisorChangeRequest = async (projectId, changeRequestId, payload) => {
  const { data } = await api.post(
    `/api/projects/${projectId}/supervisor-change/${changeRequestId}/decide`,
    payload
  )
  return data
}

export const cancelSupervisorChangeRequest = async (projectId, changeRequestId, payload = {}) => {
  const { data } = await api.post(
    `/api/projects/${projectId}/supervisor-change/${changeRequestId}/cancel`,
    payload
  )
  return data
}
