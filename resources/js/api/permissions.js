import api from './axios'

export const fetchPermissions = async () => {
  const { data } = await api.get('/api/permissions')
  return data
}
