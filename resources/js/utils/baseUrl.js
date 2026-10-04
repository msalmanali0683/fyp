/**
 * Detect Laravel base path when app is served from a subdirectory (XAMPP).
 * Examples:
 *   http://localhost/fyp/public/login  -> /fyp/public
 *   http://127.0.0.1:8000/login        -> ''
 */
export function getBaseUrl() {
  const pathname = window.location.pathname

  if (pathname.startsWith('/fyp/public')) {
    return '/fyp/public'
  }

  return ''
}

/**
 * Build a same-origin URL for files in public/storage.
 * Accepts a storage-relative path (proposals/1/file.pdf) or a legacy absolute URL.
 */
export function storageUrl(pathOrUrl) {
  if (!pathOrUrl) return null

  let relativePath = pathOrUrl

  if (typeof pathOrUrl === 'string' && pathOrUrl.includes('/storage/')) {
    relativePath = pathOrUrl.slice(pathOrUrl.indexOf('/storage/') + '/storage/'.length)
  } else if (typeof pathOrUrl === 'string' && /^https?:\/\//i.test(pathOrUrl)) {
    try {
      const url = new URL(pathOrUrl)
      const marker = '/storage/'
      const idx = url.pathname.indexOf(marker)
      if (idx >= 0) {
        relativePath = url.pathname.slice(idx + marker.length)
      } else {
        return pathOrUrl
      }
    } catch {
      return pathOrUrl
    }
  }

  return `${getBaseUrl()}/storage/${String(relativePath).replace(/^\/+/, '')}`
}
