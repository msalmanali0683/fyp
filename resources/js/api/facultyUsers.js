import api from './axios'
import { createUser, updateUser, deleteUser } from './users'

export const fetchFacultyUsers = async (params = {}) => {
  const { data } = await api.get('/api/users', {
    params: { ...params, only_role: 'faculty' },
  })
  return data
}

export const createFacultyUser = (payload) =>
  createUser({
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['faculty'],
  })

export const updateFacultyUser = (id, payload) =>
  updateUser(id, {
    ...payload,
    roles: payload.roles?.length ? payload.roles : ['faculty'],
  })

export const deleteFacultyUser = (id) => deleteUser(id)

export const downloadFacultyImportTemplate = async () => {
  const response = await api.get('/api/users/faculty/import/template', {
    responseType: 'blob',
  })

  const blob = new Blob([response.data], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'faculty-import-template.xlsx'
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

export const importFacultyUsers = async (file, programId = null) => {
  const formData = new FormData()
  formData.append('file', file)
  if (programId) {
    formData.append('program_id', programId)
  }

  const { data } = await api.post('/api/users/faculty/import', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return data
}
