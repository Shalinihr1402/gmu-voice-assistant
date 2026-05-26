import { useEffect, useMemo, useRef, useState } from "react"
import { useLocation, useNavigate } from "react-router-dom"
import Vapi from "@vapi-ai/web"

import gmuLogo from "../assets/gmu-logo.png"
import { fetchJson } from "../utils/api"
import { getStoredUiLanguage, setStoredUiLanguage } from "../utils/uiLanguage"
import "./VoiceAssistant.css"

const VOICE_LANGUAGES = {
  en: { label: "English", apiLanguage: "en" },
  hi: { label: "Hindi", apiLanguage: "hi" },
  kn: { label: "Kannada", apiLanguage: "kn" }
}

const normalizeLanguage = (value) => (
  value === "hi" || value === "kn" ? value : "en"
)

const displayText = {
  status: "Status:",
  you: "You:",
  assistant: "Assistant:",
  source: "Source:",
  suggestion: "Suggestion:",
  tapToAsk: "Tap to ask",
  openAssistant: "Open voice assistant",
  listening: "Listening...",
  connecting: "Connecting...",
  speaking: "Speaking...",
  idle: "Tap to ask",
  active: "Voice session active"
}

const INTENT_ROUTE_MAP = {
  OPEN_HOME_PAGE: "/home",
  OPEN_PROFILE_PAGE: "/profile",
  OPEN_RESULT_PAGE: "/results",
  OPEN_PAYMENT_PAGE: "/payment",
  OPEN_CERTIFICATE_PAGE: "/certificate",
  OPEN_DASHBOARD_PAGE: "/dashboard",
  OPEN_REGISTRATION_PAGE: "/registration",
  OPEN_PORTAL_PAGE: "/portal"
}

const PAGE_ROUTE_MAP = {
  home: "/home",
  profile: "/profile",
  result: "/results",
  results: "/results",
  payment: "/payment",
  certificate: "/certificate",
  dashboard: "/dashboard",
  registration: "/registration",
  portal: "/portal"
}

const ALLOWED_NAVIGATION_PATHS = new Set([
  "/home",
  "/profile",
  "/results",
  "/payment",
  "/certificate",
  "/dashboard",
  "/registration",
  "/portal",
  "/attendance-analytics"
])

const resolveNavigationPath = (action, result = {}) => {
  const intentPath = INTENT_ROUTE_MAP[result.intent]
  if (intentPath) return intentPath

  const pageKey = String(action?.page || result.page || result.target_page || result.entities?.target_page || "").toLowerCase()
  if (PAGE_ROUTE_MAP[pageKey]) return PAGE_ROUTE_MAP[pageKey]

  const rawPath = String(action?.path || "").trim()
  if (!rawPath) return ""

  const [basePath] = rawPath.split("?")
  if (!ALLOWED_NAVIGATION_PATHS.has(basePath)) return ""
  return rawPath
}

const getVapiText = (message) => (
  message?.transcript
  || message?.text
  || message?.message?.content
  || message?.content
  || ""
)

const getVapiRole = (message) => (
  message?.role || message?.message?.role || ""
)

const parseMaybeJson = (value) => {
  if (typeof value !== "string") return value
  const trimmed = value.trim()
  if (!trimmed || (!trimmed.startsWith("{") && !trimmed.startsWith("["))) return value
  try {
    return JSON.parse(trimmed)
  } catch {
    return value
  }
}

const getToolCallId = (value) => String(value?.toolCallId || value?.tool_call_id || value?.id || value?.toolCall?.id || "")

const getToolResultTime = (value) => {
  const candidates = [value?.time, value?.timestamp, value?.createdAt, value?.startedAt, value?.endedAt]
  for (const candidate of candidates) {
    if (typeof candidate === "number" && Number.isFinite(candidate)) return candidate > 100000000000 ? candidate : candidate * 1000
    if (typeof candidate === "string" && candidate.trim()) {
      const parsed = Date.parse(candidate)
      if (Number.isFinite(parsed)) return parsed
    }
  }
  return 0
}

const coerceStructuredToolResult = (value) => {
  const parsed = parseMaybeJson(value)
  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return null

  if (isStructuredToolResult(parsed)) {
    return {
      ...parsed,
      toolCallId: parsed.toolCallId || getToolCallId(parsed),
      toolResultTime: parsed.toolResultTime || getToolResultTime(parsed)
    }
  }

  const payloadCandidates = [
    parsed.result,
    parsed.content,
    parsed.text,
    parsed.output,
    parsed.data,
    parsed.message?.result,
    parsed.message?.content,
    parsed.message?.text
  ]

  for (const candidate of payloadCandidates) {
    const nested = parseMaybeJson(candidate)
    if (nested && typeof nested === "object" && !Array.isArray(nested) && isStructuredToolResult(nested)) {
      return {
        ...nested,
        toolCallId: nested.toolCallId || getToolCallId(parsed),
        toolName: nested.toolName || parsed.name || parsed.toolName || "",
        toolResultTime: nested.toolResultTime || getToolResultTime(parsed) || getToolResultTime(nested)
      }
    }
  }

  return null
}

const isStructuredToolResult = (value) => (
  !!value
  && typeof value === "object"
  && (
    value.client_action
    || value.clientAction
    || value.action
    || typeof value.reply === "string"
    || Array.isArray(value.quick_actions)
    || typeof value.suggestion === "string"
  )
)

const findToolResults = (value, seen = new WeakSet(), depth = 0) => {
  if (!value) return []

  const directResult = coerceStructuredToolResult(value)
  if (directResult) return [directResult]

  if (typeof value !== "object") return []
  if (seen.has(value)) return []
  seen.add(value)

  const results = []
  if (isStructuredToolResult(value)) {
    results.push(value)
  }

  const nestedCandidates = []
  if (value.result !== undefined) nestedCandidates.push(value.result)
  if (value.content !== undefined) nestedCandidates.push(value.content)
  if (value.text !== undefined) nestedCandidates.push(value.text)
  if (value.output !== undefined) nestedCandidates.push(value.output)
  if (value.data !== undefined) nestedCandidates.push(value.data)
  if (value.response !== undefined) nestedCandidates.push(value.response)
  if (value.toolCallResult !== undefined) nestedCandidates.push(value.toolCallResult)
  if (Array.isArray(value.toolCallResults)) nestedCandidates.push(...value.toolCallResults)

  if (depth === 0 && value.message && typeof value.message === "object" && !Array.isArray(value.message)) {
    nestedCandidates.push(value.message)
  }

  nestedCandidates.forEach((item) => {
    results.push(...findToolResults(item, seen, depth + 1))
  })

  return results
}

const findLatestToolResultFromHistory = (message) => {
  const history = Array.isArray(message?.messages)
    ? message.messages
    : Array.isArray(message?.message?.messages)
      ? message.message.messages
      : []

  if (!history.length) return null

  let latest = null
  history.forEach((item, index) => {
    const candidates = findToolResults(item).map((result) => ({
      result,
      index,
      toolCallId: getToolCallId(result),
      time: result.toolResultTime || getToolResultTime(item) || index
    }))

    candidates.forEach((candidate) => {
      if (!candidate.toolCallId) return
      if (!latest || candidate.time > latest.time || (candidate.time === latest.time && candidate.index > latest.index)) {
        latest = candidate
      }
    })
  })

  return latest ? latest.result : null
}

const selectNewestToolResult = (message) => {
  const directResults = findToolResults({ ...message, messages: undefined })
  if (directResults.length) {
    return directResults[directResults.length - 1]
  }

  return findLatestToolResultFromHistory(message)
}
const getVapiMessageTimestamp = (message) => {
  const candidates = [
    message?.timestamp,
    message?.createdAt,
    message?.time,
    message?.message?.timestamp,
    message?.message?.createdAt,
    message?.message?.time
  ]

  for (const candidate of candidates) {
    if (typeof candidate === "number" && Number.isFinite(candidate)) {
      return candidate > 100000000000 ? candidate : candidate * 1000
    }
    if (typeof candidate === "string" && candidate.trim()) {
      const parsed = Date.parse(candidate)
      if (Number.isFinite(parsed)) return parsed
    }
  }

  return Date.now()
}

const getVapiMessageId = (message) => (
  String(message?.id || message?.messageId || message?.message?.id || message?.message?.messageId || "")
)

const getVapiErrorMessage = (error) => {
  const candidates = [
    error?.message,
    error?.error?.message,
    error?.errorMsg,
    error?.details,
    error?.response?.message,
    error?.response?.data?.message
  ]
  const message = candidates.find((candidate) => typeof candidate === "string" && candidate.trim())
  if (message) return `Vapi voice session failed: ${message}`

  try {
    return `Vapi voice session failed: ${JSON.stringify(error).slice(0, 220)}`
  } catch {
    return "Vapi voice session failed."
  }
}

const VoiceAssistant = () => {
  const navigate = useNavigate()
  const location = useLocation()
  const [isOpen, setIsOpen] = useState(false)
  const [isConnecting, setIsConnecting] = useState(false)
  const [isCallActive, setIsCallActive] = useState(false)
  const [isSpeaking, setIsSpeaking] = useState(false)
  const [transcript, setTranscript] = useState("")
  const [response, setResponse] = useState("")
  const [replySource, setReplySource] = useState("")
  const [suggestionText, setSuggestionText] = useState("")
  const [quickActions, setQuickActions] = useState([])
  const [errorMessage, setErrorMessage] = useState("")
  const [voiceLanguage, setVoiceLanguage] = useState(() => normalizeLanguage(getStoredUiLanguage()))

  const vapiRef = useRef(null)
  const callActiveRef = useRef(false)
  const lastAppliedResultRef = useRef({ key: "", at: 0 })
  const latestNavigationReplyRef = useRef({ reply: "", at: 0, path: "" })
  const messageSequenceRef = useRef(0)
  const latestToolMessageRef = useRef({ timestamp: 0, sequence: 0, id: "" })
  const processedToolCallIdsRef = useRef(new Set())
  const latestProcessedToolCallIdRef = useRef("")
  const voiceLanguageRef = useRef(voiceLanguage)

  const languageConfig = useMemo(() => (
    VOICE_LANGUAGES[voiceLanguage] || VOICE_LANGUAGES.en
  ), [voiceLanguage])

  useEffect(() => {
    callActiveRef.current = isCallActive
  }, [isCallActive])

  useEffect(() => {
    voiceLanguageRef.current = voiceLanguage
    console.log("VOICE LANGUAGE STATE:", { current: voiceLanguage })
  }, [voiceLanguage])

  useEffect(() => {
    const handleLanguageChange = (event) => {
      const requested = event.detail
      const normalized = normalizeLanguage(requested)
      console.log("VOICE LANGUAGE STORAGE EVENT:", { current: voiceLanguageRef.current, requested, normalized })
      setVoiceLanguage((current) => current === normalized ? current : normalized)
    }

    window.addEventListener("gmu-ui-language-change", handleLanguageChange)
    return () => window.removeEventListener("gmu-ui-language-change", handleLanguageChange)
  }, [])

  useEffect(() => () => {
    if (vapiRef.current) {
      try {
        vapiRef.current.stop()
      } catch {}
      vapiRef.current = null
    }
  }, [])

  useEffect(() => {
    console.log("VOICE NAVIGATION ROUTE:", location.pathname)
  }, [location.pathname])

  const applyLanguage = (language) => {
    const normalized = normalizeLanguage(language)
    console.log("VOICE LANGUAGE UPDATE:", {
      current: voiceLanguageRef.current,
      requested: language,
      normalized
    })
    setVoiceLanguage(normalized)
    setStoredUiLanguage(normalized)
    return normalized !== voiceLanguageRef.current
  }

  const loadVapiConfig = async (language = voiceLanguage) => {
    const requestedLanguage = VOICE_LANGUAGES[normalizeLanguage(language)]?.apiLanguage || "en"
    console.log("VOICE LANGUAGE CONFIG REQUEST:", { current: voiceLanguageRef.current, requested: language, apiLanguage: requestedLanguage })
    return fetchJson(`vapiConfig.php?language=${encodeURIComponent(requestedLanguage)}`)
  }

  const applyNavigation = (action, result = {}, toolCallId = "") => {
    if (action?.type !== "navigate") return false

    const path = resolveNavigationPath(action, result)
    console.log("VOICE NAVIGATION:", action)
    console.log("VOICE NAVIGATION TARGET:", path)
    console.log("NAVIGATING TO:", path)
    if (path === "/certificate") {
      console.log("CERTIFICATE NAVIGATION:", path)
    }
    if (!path) return false

    const reply = String(result.reply || "")
    setResponse(reply)
    setSuggestionText(result.suggestion ? String(result.suggestion) : "")
    setQuickActions(Array.isArray(result.quick_actions) ? result.quick_actions : [])
    setErrorMessage("")

    setReplySource("tool_result")

    latestNavigationReplyRef.current = { reply, at: Date.now(), path }
    console.log("BEFORE NAVIGATE:", path)
    console.log("CURRENT URL:", window.location.pathname)
    console.log("NAVIGATE TOOL CALL ID:", toolCallId || latestProcessedToolCallIdRef.current)
    navigate(path, { replace: true })
    console.log("VOICE NAVIGATION ACTUAL:", path)
    console.log("NAVIGATE EXECUTED:", path)
    setTimeout(() => {
      console.log("AFTER NAVIGATE URL:", window.location.pathname)
    }, 300)
    return true
  }

  const applyToolResult = (result, messageMeta = {}) => {
    if (!result || typeof result !== "object") return false

    const action = result.client_action || result.clientAction || result.action || null
    const key = [
      result.intent || "",
      result.route || "",
      result.reply || "",
      action?.type || "",
      action?.path || action?.language || ""
    ].join("|")

    const now = Date.now()
    const messageTimestamp = Number(messageMeta.timestamp || now)
    const messageSequence = Number(messageMeta.sequence || 0)
    const toolCallId = getToolCallId(result)
    const messageId = toolCallId || String(messageMeta.id || "")
    const latestTool = latestToolMessageRef.current

    console.log("TOOL CALL ID:", toolCallId)
    console.log("LATEST RESULT:", result)

    if (toolCallId && processedToolCallIdsRef.current.has(toolCallId)) {
      console.log("IGNORING STALE TOOL RESULT:", toolCallId)
      return true
    }

    if (messageId && latestTool.id && messageId === latestTool.id) {
      console.log("VOICE MESSAGE IGNORED duplicate tool message id:", messageId)
      return true
    }

    if (messageTimestamp < latestTool.timestamp || (messageTimestamp === latestTool.timestamp && messageSequence < latestTool.sequence)) {
      console.log("VOICE MESSAGE IGNORED stale tool result:", {
        messageTimestamp,
        messageSequence,
        latestTool,
        key
      })
      return true
    }

    const lastApplied = lastAppliedResultRef.current
    if (lastApplied.key === key && now - lastApplied.at < 2500) {
      console.log("VOICE MESSAGE IGNORED duplicate tool result:", key)
      return true
    }

    latestToolMessageRef.current = { timestamp: messageTimestamp, sequence: messageSequence, id: messageId }
    if (toolCallId) {
      processedToolCallIdsRef.current.add(toolCallId)
      latestProcessedToolCallIdRef.current = toolCallId
    }
    lastAppliedResultRef.current = { key, at: now }
    console.log("TOOL RESULT:", result)
    console.log("TOOL REPLY:", result.reply || "")
    console.log("VOICE MESSAGE ACCEPTED tool result:", {
      messageTimestamp,
      messageSequence,
      messageId,
      intent: result.intent,
      route: result.route,
      action
    })

    if (action?.type === "set_language" && action.language) {
      applyLanguage(action.language)
      console.log("VOICE LANGUAGE TOOL RESULT APPLIED:", { requested: action.language, toolCallId })
    }

    if (action?.type === "navigate" && applyNavigation(action, result, toolCallId)) {
      return true
    }

    if (typeof result.reply === "string") setResponse(result.reply)
    if (result.suggestion) setSuggestionText(String(result.suggestion))
    else setSuggestionText("")
    setQuickActions(Array.isArray(result.quick_actions) ? result.quick_actions : [])

    setReplySource("tool_result")

    return true
  }

  const handleVapiMessage = (message) => {
    if (!message || typeof message !== "object") return

    console.log("FULL VAPI MESSAGE:", message)
    const role = getVapiRole(message)
    const text = String(getVapiText(message) || "").trim()
    const messageMeta = {
      timestamp: getVapiMessageTimestamp(message),
      sequence: messageSequenceRef.current + 1,
      id: getVapiMessageId(message),
      type: message.type || message.message?.type || "",
      role
    }
    messageSequenceRef.current = messageMeta.sequence

    console.log("VOICE MESSAGE RECEIVED:", messageMeta)

    if (text && role === "user") {
      setTranscript(text)
    }

    if (text && role === "assistant") {
      console.log("VOICE MESSAGE IGNORED raw assistant transcript:", {
        timestamp: messageMeta.timestamp,
        sequence: messageMeta.sequence,
        text
      })
    }

    const toolResult = selectNewestToolResult(message)
    console.log("VOICE TOOL RESULTS FOUND:", toolResult ? 1 : 0)
    if (!toolResult) {
      if (messageMeta.type === "conversation-update") {
        console.log("VOICE MESSAGE IGNORED conversation-update without current tool result:", messageMeta)
      }
      return
    }

    applyToolResult(toolResult, messageMeta)
  }

  const getOrCreateVapi = (publicKey) => {
    if (vapiRef.current) return vapiRef.current

    const vapi = new Vapi(publicKey)
    vapi.on("call-start", () => {
      setIsConnecting(false)
      setIsCallActive(true)
      setErrorMessage("")
    })
    vapi.on("call-end", () => {
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
    })
    vapi.on("speech-start", () => setIsSpeaking(true))
    vapi.on("speech-end", () => setIsSpeaking(false))
    vapi.on("message", handleVapiMessage)
    vapi.on("error", (error) => {
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
      setErrorMessage(getVapiErrorMessage(error))
    })

    vapiRef.current = vapi
    return vapi
  }

  const startVapi = async () => {
    setIsOpen(true)
    setIsConnecting(true)
    setErrorMessage("")
    setTranscript("")
    setResponse("")
    setReplySource("vapi")

    const config = await loadVapiConfig()
    if (!config?.enabled || !config.public_key) {
      throw new Error(config?.setup_hint || "Vapi is not configured.")
    }

    const vapi = getOrCreateVapi(config.public_key)
    await vapi.start(config.assistant, config.assistant_overrides || {})
  }

  const stopVapi = () => {
    if (vapiRef.current) {
      try {
        vapiRef.current.stop()
      } catch {}
    }
    setIsConnecting(false)
    setIsCallActive(false)
    setIsSpeaking(false)
  }

  const toggleVapi = async () => {
    try {
      if (callActiveRef.current || isConnecting) {
        stopVapi()
        return
      }
      await startVapi()
    } catch (error) {
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
      setErrorMessage(error?.message || "Unable to start Vapi voice session.")
    }
  }

  const closeAssistant = () => {
    stopVapi()
    setIsOpen(false)
    setTranscript("")
    setResponse("")
    setReplySource("")
    setSuggestionText("")
    setQuickActions([])
    setErrorMessage("")
  }

  const handleQuickAction = async (action) => {
    const prompt = String(action?.prompt || "").trim()
    if (!prompt || !callActiveRef.current || !vapiRef.current) return

    setTranscript(prompt)
    try {
      await vapiRef.current.send({ type: "add-message", message: { role: "user", content: prompt } })
    } catch {
      setErrorMessage("Quick action could not be sent to Vapi.")
    }
  }

  const statusLabel = isConnecting
    ? displayText.connecting
    : isSpeaking
      ? displayText.speaking
      : isCallActive
        ? displayText.listening
        : displayText.idle

  const buttonTitle = isCallActive || isConnecting ? "Stop voice session" : displayText.openAssistant

  return (
    <div className="voice-assistant-container">
      {isOpen && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>x</button>
          <h3>GMU VoiceBot</h3>

          <div className="voice-language-toggle">
            {Object.entries(VOICE_LANGUAGES).map(([key, option]) => (
              <button
                key={key}
                type="button"
                className={key === voiceLanguage ? "active" : ""}
                disabled={isCallActive || isConnecting}
                onClick={() => applyLanguage(key)}
              >
                {option.label}
              </button>
            ))}
          </div>

          <div className="voice-content">
            <p><b>{displayText.status}</b> {statusLabel}</p>
            <p><b>Language:</b> {languageConfig.label}</p>
            <p><b>{displayText.you}</b> {transcript}</p>
            <p><b>{displayText.assistant}</b> {response}</p>
            {suggestionText && <p className="voice-suggestion"><b>{displayText.suggestion}</b> {suggestionText}</p>}
            {!!quickActions.length && (
              <div className="voice-quick-actions">
                {quickActions.map((action, index) => (
                  <button
                    key={`${action.label || "action"}-${index}`}
                    type="button"
                    className="voice-quick-action-btn"
                    disabled={!isCallActive}
                    onClick={() => { void handleQuickAction(action) }}
                  >
                    {action.label || action.prompt}
                  </button>
                ))}
              </div>
            )}
            {replySource && <p><b>{displayText.source}</b> {replySource}</p>}
            {errorMessage && <p className="error-message">{errorMessage}</p>}
            {!isCallActive && !isConnecting && <p className="voice-hint">Tap the GMU button to start a Vapi voice session.</p>}
          </div>
        </div>
      )}

      <button
        className="voice-assistant-btn"
        onClick={toggleVapi}
        title={buttonTitle}
        aria-label={buttonTitle}
      >
        <img src={gmuLogo} alt="GMU VoiceBot" className="voice-logo" />
      </button>
      {isOpen && !isCallActive && !isConnecting && <div className="voice-action-badge">{displayText.tapToAsk}</div>}
    </div>
  )
}

export default VoiceAssistant
