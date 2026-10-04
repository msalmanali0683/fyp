export const formatApiError = (error, fallback = 'Request failed.') => {
  const data = error?.response?.data
  if (!data) return fallback

  if (data.errors && typeof data.errors === 'object') {
    const messages = Object.values(data.errors).flat().filter(Boolean)
    if (messages.length) return messages.join(' ')
  }

  return data.message || fallback
}
