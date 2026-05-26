import { useEffect, useState } from "react"

export const UI_LANGUAGE_STORAGE_KEY = "gmu-ui-language"

const normalizeLanguage = (value) => (
  value === "hi" || value === "kn" ? value : "en"
)

export const getStoredUiLanguage = () => {
  try {
    return normalizeLanguage(window.localStorage.getItem(UI_LANGUAGE_STORAGE_KEY) || "en")
  } catch {
    return "en"
  }
}

export const setStoredUiLanguage = (language) => {
  const normalized = normalizeLanguage(language)

  try {
    window.localStorage.setItem(UI_LANGUAGE_STORAGE_KEY, normalized)
    window.dispatchEvent(new CustomEvent("gmu-ui-language-change", { detail: normalized }))
  } catch {}

  return normalized
}


export const clearStoredUiLanguage = () => {
  try {
    window.localStorage.removeItem(UI_LANGUAGE_STORAGE_KEY)
    window.dispatchEvent(new CustomEvent("gmu-ui-language-change", { detail: "en" }))
  } catch {}

  return "en"
}

export const useUiLanguage = () => {
  const [language, setLanguage] = useState(getStoredUiLanguage())

  useEffect(() => {
    const handleCustomChange = (event) => {
      setLanguage(normalizeLanguage(event.detail))
    }

    const handleStorage = (event) => {
      if (event.key === UI_LANGUAGE_STORAGE_KEY) {
        setLanguage(normalizeLanguage(event.newValue || "en"))
      }
    }

    window.addEventListener("gmu-ui-language-change", handleCustomChange)
    window.addEventListener("storage", handleStorage)

    return () => {
      window.removeEventListener("gmu-ui-language-change", handleCustomChange)
      window.removeEventListener("storage", handleStorage)
    }
  }, [])

  return language
}
