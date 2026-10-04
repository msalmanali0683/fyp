import api, { csrf } from './axios'

export const login = async (payload) => {
  await csrf()
  const { data } = await api.post('/api/login', payload)
  return data
}

export const logout = async () => {
  const { data } = await api.post('/api/logout')
  return data
}

export const fetchUser = async () => {
  const { data } = await api.get('/api/user')
  return data
}

export const updateProfile = async (payload) => {
  const { data } = await api.put('/api/profile', payload)
  return data
}

export const updatePassword = async (payload) => {
  const { data } = await api.put('/api/profile/password', payload)
  return data
}

export const forgotPassword = async (payload) => {
  await csrf()
  const { data } = await api.post('/api/forgot-password', payload)
  return data
}

export const resetPassword = async (payload) => {
  await csrf()
  const { data } = await api.post('/api/reset-password', payload)
  return data
}
