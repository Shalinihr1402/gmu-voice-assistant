const DEFAULT_BACKEND_ROOT = "http://localhost:8080/gmu-voice-bot/gmu-voice-assistant/backend"

const trimTrailingSlashes = (value) => String(value || "").replace(/\/+$/, "")

const trimLeadingSlashes = (value) => String(value || "").replace(/^\/+/, "")

const getConfiguredBackendRoot = () => {
  const explicitRoot = import.meta.env.VITE_BACKEND_ROOT
  if (explicitRoot) {
    return trimTrailingSlashes(explicitRoot)
  }

  return trimTrailingSlashes(DEFAULT_BACKEND_ROOT)
}

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
