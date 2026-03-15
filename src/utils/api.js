const BACKEND_ROOT = "/gmu-voice-assistant/backend"
const DEV_PROXY_ROOT = `/api${BACKEND_ROOT}`

const getBackendRoot = () => (
  import.meta.env.DEV ? DEV_PROXY_ROOT : BACKEND_ROOT
)

export const getBackendUrl = (path = "") => {
  const normalizedPath = path.replace(/^\/+/, "")
  return `${getBackendRoot()}/${normalizedPath}`
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
