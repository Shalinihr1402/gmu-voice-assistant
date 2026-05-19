const BACKEND_ROOT = "http://localhost:8080/gmu-voice-assistant/backend"
const DEV_PROXY_ROOT = "/api/gmu-voice-assistant/backend"

const getBackendRoot = () => (
  import.meta.env.DEV ? DEV_PROXY_ROOT : BACKEND_ROOT
)

export const getBackendUrl = (path = "") => {
  const rawPath = String(path || "")

  if (/^https?:\/\//i.test(rawPath)) {
    return rawPath
  }

  const backendRoot = trimTrailingSlashes(getConfiguredBackendRoot() || DEFAULT_BACKEND_ROOT)
  const normalizedPath = trimLeadingSlashes(rawPath)

  return normalizedPath ? `${backendRoot}/${normalizedPath}` : backendRoot
}

export const fetchJson = async (path, options = {}) => {
  const response = await fetch(getBackendUrl(path), {
    credentials: "include",
    ...options
  })

  const text = await response.text()
  let data = null

  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      const snippet = text.replace(/\s+/g, " ").slice(0, 140)
      throw new Error(
        `Expected JSON from ${getBackendUrl(path)} but received ${response.status} ${response.statusText}: ${snippet}`
      )
    }
  }

  if (!response.ok) {
    const message = data?.message || data?.error || `Request failed with status ${response.status}`
    throw new Error(message)
  }

  return data
}
