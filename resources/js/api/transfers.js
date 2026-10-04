import api from './axios'

export const fetchTransferTargets = async () => {
  const { data } = await api.get('/api/transfer-requests/targets')
  return data
}

export const fetchTransferRequests = async () => {
  const { data } = await api.get('/api/transfer-requests')
  return data
}

export const createTransferRequest = async (payload) => {
  const { data } = await api.post('/api/transfer-requests', payload)
  return data
}

export const selectTransferTarget = async (requestId, payload) => {
  const { data } = await api.post(`/api/transfer-requests/${requestId}/select-target`, payload)
  return data
}

export const decideTransferRequest = async (requestId, payload) => {
  const { data } = await api.post(`/api/transfer-requests/${requestId}/decide`, payload)
  return data
}

export const cancelTransferRequest = async (requestId) => {
  const { data } = await api.post(`/api/transfer-requests/${requestId}/cancel`)
  return data
}

export const fetchTransferableMembers = async (projectId, search = '') => {
  const { data } = await api.get('/api/proposals/eligible-members', {
    params: { project_id: projectId, transferable: 1, search: search || undefined },
  })
  return data
}
