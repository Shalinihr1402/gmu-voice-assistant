import { useEffect, useMemo, useRef, useState } from "react"
import { useLocation, useNavigate } from "react-router-dom"
import Vapi from "@vapi-ai/web"

import gmuLogo from "../assets/gmu-logo.png"
import { fetchJson } from "../utils/api"
import { getStoredUiLanguage, setStoredUiLanguage } from "../utils/uiLanguage"
import "./VoiceAssistant.css"

const VOICE_LANGUAGES = {
  en: {
    label: "English",
    apiLanguage: "en",
    confirmation: "English selected. Tap the GMU button and ask your question in English."
  },
  hi: {
    label: "Hindi",
    apiLanguage: "hi",
    confirmation: "Hindi selected. GMU button tap karke Hindi ya Hinglish mein apna question poochiye."
  },
  kn: {
    label: "Kannada",
    apiLanguage: "kn",
    confirmation: "Kannada selected. GMU button tap maadi Kannada athava Kanglish nalli nimma question keli."
  }
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

const buildResultPath = (path, resultRequest = {}) => {
  const rawPath = String(path || "/results").trim() || "/results"
  const [basePath, query = ""] = rawPath.split("?")
  if (basePath !== "/results") return rawPath

  const params = new URLSearchParams(query)
  const mappings = {
    semester: resultRequest.semester,
    usn: resultRequest.usn,
    exam: resultRequest.exam || resultRequest.examType,
    year: resultRequest.year,
    season: resultRequest.season
  }

  Object.entries(mappings).forEach(([key, value]) => {
    const normalized = String(value || "").trim()
    if (normalized && !params.has(key)) params.set(key, normalized)
  })

  const nextQuery = params.toString()
  return nextQuery ? `${basePath}?${nextQuery}` : basePath
}

const resolveNavigationPath = (action, result = {}) => {
  const rawPath = String(action?.path || "").trim()
  const [rawBasePath] = rawPath.split("?")

  if (rawBasePath === "/results" || result.intent === "OPEN_FILTERED_RESULT") {
    return buildResultPath(rawPath || "/results", action?.result_request || result.result_request || {})
  }

  const intentPath = INTENT_ROUTE_MAP[result.intent]
  if (intentPath) return intentPath

  const pageKey = String(action?.page || result.page || result.target_page || result.entities?.target_page || "").toLowerCase()
  if (PAGE_ROUTE_MAP[pageKey]) return PAGE_ROUTE_MAP[pageKey]

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

  const raw = (() => {
    try {
      return [message, JSON.stringify(error)].filter(Boolean).join(" ")
    } catch {
      return message || ""
    }
  })()

  if (/meeting has ended|daily-error|ejected|no-room|room was deleted/i.test(raw)) {
    return "Voice session ended. Tap the GMU button to start a new voice session."
  }

  if (/microphone|permission|notallowed|not allowed|denied/i.test(raw)) {
    return "Microphone permission is blocked. Please allow microphone access and tap the GMU button again."
  }

  if (/public.?key|assistant|unauthorized|invalid/i.test(raw)) {
    return "Vapi configuration is invalid. Please check the public key and assistant settings."
  }

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
  const [visualPayload, setVisualPayload] = useState(null)
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
  const connectTimeoutRef = useRef(null)
  const startAttemptRef = useRef(0)
  const currentCallHasUserInputRef = useRef(false)
  const toggleInProgressRef = useRef(false)
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

  const clearConnectTimeout = () => {
    if (connectTimeoutRef.current) {
      clearTimeout(connectTimeoutRef.current)
      connectTimeoutRef.current = null
    }
  }

  const markVapiConnected = () => {
    clearConnectTimeout()
    setIsConnecting(false)
    setIsCallActive(true)
    setErrorMessage("")
  }

  const disposeVapi = () => {
    clearConnectTimeout()
    const activeVapi = vapiRef.current
    vapiRef.current = null
    if (activeVapi) {
      try {
        activeVapi.stop()
      } catch {}
    }
  }

  useEffect(() => () => {
    disposeVapi()
  }, [])

  useEffect(() => {
    console.log("VOICE NAVIGATION ROUTE:", location.pathname)
  }, [location.pathname])

  const applyLanguage = (language) => {
    const normalized = normalizeLanguage(language)
    const changed = normalized !== voiceLanguageRef.current
    console.log("VOICE LANGUAGE UPDATE:", {
      current: voiceLanguageRef.current,
      requested: language,
      normalized,
      changed
    })
    voiceLanguageRef.current = normalized
    setVoiceLanguage(normalized)
    setStoredUiLanguage(normalized)
    return changed
  }

  const handleLanguageButtonClick = (language) => {
    const normalized = normalizeLanguage(language)
    const option = VOICE_LANGUAGES[normalized] || VOICE_LANGUAGES.en
    const wasRunning = callActiveRef.current || isConnecting

    if (wasRunning) stopVapi()
    applyLanguage(normalized)
    setIsOpen(true)
    setTranscript("")
    setResponse(option.confirmation)
    setReplySource("language_button")
    setSuggestionText(wasRunning ? "Voice session restarted for the selected language. Tap again to speak." : "Tap the GMU button to start speaking in the selected language.")
    setQuickActions([])
    setVisualPayload(null)
    setErrorMessage("")
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
    setVisualPayload(result.visual || null)
    setErrorMessage("")

    setReplySource("tool_result")

    latestNavigationReplyRef.current = { reply, at: Date.now(), path }
    console.log("BEFORE NAVIGATE:", path)
    console.log("CURRENT URL:", window.location.pathname)
    console.log("NAVIGATE TOOL CALL ID:", toolCallId || latestProcessedToolCallIdRef.current)
    navigate(path, { replace: true, state: { voiceAction: action, voiceResult: result } })
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
    setVisualPayload(result.visual || null)

    setReplySource("tool_result")

    return true
  }

  const handleVapiMessage = (message) => {
    if (!message || typeof message !== "object") return

    markVapiConnected()
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
      currentCallHasUserInputRef.current = true
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
    const vapi = new Vapi(publicKey)
    const isCurrentVapi = () => vapiRef.current === vapi

    vapi.on("call-start", () => {
      if (!isCurrentVapi()) return
      toggleInProgressRef.current = false
      markVapiConnected()
    })
    vapi.on("call-end", () => {
      if (!isCurrentVapi()) return
      clearConnectTimeout()
      toggleInProgressRef.current = false
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
      vapiRef.current = null
    })
    vapi.on("speech-start", () => {
      if (!isCurrentVapi()) return
      markVapiConnected()
      setIsSpeaking(true)
    })
    vapi.on("speech-end", () => {
      if (!isCurrentVapi()) return
      setIsSpeaking(false)
    })
    vapi.on("message", (message) => {
      if (!isCurrentVapi()) return
      handleVapiMessage(message)
    })
    vapi.on("error", (error) => {
      if (!isCurrentVapi()) return
      clearConnectTimeout()
      toggleInProgressRef.current = false
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
      vapiRef.current = null
      setErrorMessage(getVapiErrorMessage(error))
    })

    vapiRef.current = vapi
    return vapi
  }

  const startVapi = async () => {
    disposeVapi()
    setIsOpen(true)
    setIsConnecting(true)
    setErrorMessage("")
    setTranscript("")
    setResponse("")
    setSuggestionText("")
    setQuickActions([])
    currentCallHasUserInputRef.current = false
    latestToolMessageRef.current = { timestamp: 0, sequence: 0, id: "" }
    lastAppliedResultRef.current = { key: "", at: 0 }
    processedToolCallIdsRef.current = new Set()
    latestProcessedToolCallIdRef.current = ""
    setReplySource("vapi")

    const config = await loadVapiConfig(voiceLanguageRef.current)
    if (!config?.enabled || !config.public_key) {
      throw new Error(config?.setup_hint || "Vapi is not configured.")
    }

    const vapi = getOrCreateVapi(config.public_key)
    const attemptId = startAttemptRef.current + 1
    startAttemptRef.current = attemptId
    clearConnectTimeout()
    connectTimeoutRef.current = setTimeout(() => {
      if (startAttemptRef.current !== attemptId || callActiveRef.current) return
      console.log("VAPI CONNECT TIMEOUT:", attemptId)
      disposeVapi()
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
      setErrorMessage("Voice connection timed out. Please allow microphone access, check Vapi credits/settings, and tap again.")
    }, 15000)

    const assistantStartConfig = config.assistant || config.assistant_id
    if (!assistantStartConfig) {
      throw new Error("Vapi assistant is not configured.")
    }
    console.log("VAPI START ATTEMPT:", {
      attemptId,
      usingAssistantId: Boolean(!config.assistant && config.assistant_id),
      assistantId: config.assistant_id || "inline-assistant"
    })
    await vapi.start(assistantStartConfig, config.assistant_overrides || {})
  }

  const stopVapi = ({ closePanel = false, clearConversation = false } = {}) => {
    startAttemptRef.current += 1
    toggleInProgressRef.current = false
    disposeVapi()
    setIsConnecting(false)
    setIsCallActive(false)
    setIsSpeaking(false)
    currentCallHasUserInputRef.current = false
    latestToolMessageRef.current = { timestamp: 0, sequence: 0, id: "" }
    processedToolCallIdsRef.current = new Set()
    latestProcessedToolCallIdRef.current = ""
    lastAppliedResultRef.current = { key: "", at: 0 }

    if (clearConversation) {
      setTranscript("")
      setResponse("")
      setReplySource("")
      setSuggestionText("")
      setQuickActions([])
      setVisualPayload(null)
      setErrorMessage("")
    }

    if (closePanel) setIsOpen(false)
  }

  const toggleVapi = async () => {
    if (toggleInProgressRef.current) return

    if (callActiveRef.current || isConnecting) {
      stopVapi({ closePanel: true, clearConversation: true })
      return
    }

    toggleInProgressRef.current = true
    try {
      await startVapi()
    } catch (error) {
      clearConnectTimeout()
      toggleInProgressRef.current = false
      setIsConnecting(false)
      setIsCallActive(false)
      setIsSpeaking(false)
      vapiRef.current = null
      setErrorMessage(getVapiErrorMessage(error))
    }
  }

  const closeAssistant = () => {
    stopVapi({ closePanel: true, clearConversation: true })
  }

  const handleQuickAction = async (action) => {
    const prompt = String(action?.prompt || "").trim()
    if (!prompt || !callActiveRef.current || !vapiRef.current) return

    currentCallHasUserInputRef.current = true
    setTranscript(prompt)
    try {
      await vapiRef.current.send({ type: "add-message", message: { role: "user", content: prompt } })
    } catch {
      setErrorMessage("Quick action could not be sent to Vapi.")
    }
  }


  const renderAttendanceVisual = (visual) => {
    if (!visual || visual.type !== "attendance_chart") return null
    const subjects = Array.isArray(visual.subjects) ? visual.subjects : []
    const summary = visual.summary || {}
    if (!subjects.length) return null
    const overall = Number(summary.overall_percentage || 0)
    const belowCount = Number(summary.below_threshold_count || 0)

    return (
      <section className="voice-attendance-card">
        <div className="voice-attendance-topline">
          <div>
            <span>Semester {visual.semester}</span>
            <h4>Subject-wise Attendance</h4>
          </div>
          <strong className={overall < 75 ? "low" : "safe"}>{overall}%</strong>
        </div>

        <div className="voice-attendance-stats">
          <div><span>Attended</span><b>{summary.attended_classes}/{summary.total_classes}</b></div>
          <div><span>Subjects</span><b>{summary.subject_count}</b></div>
          <div className={belowCount > 0 ? "warning" : "safe"}><span>Below 75%</span><b>{belowCount}</b></div>
        </div>

        <div className="voice-attendance-bars">
          {subjects.map((subject) => {
            const percentage = Number(subject.percentage || 0)
            const width = `${Math.min(100, Math.max(percentage, 5))}%`
            return (
              <div key={`${subject.course_code}-${subject.course_title}`} className="voice-attendance-row">
                <div className="voice-attendance-label">
                  <b>{subject.course_code}</b>
                  <span>{subject.course_title}</span>
                </div>
                <div className="voice-attendance-track" aria-label={`${subject.course_title} ${percentage}%`}>
                  <div className={`voice-attendance-fill ${percentage < 75 ? "low" : "safe"}`} style={{ width }}>
                    <span>{percentage}%</span>
                  </div>
                  <i />
                </div>
                <div className="voice-attendance-meta">
                  <span>{subject.attended_classes}/{subject.total_classes} classes</span>
                  <strong className={percentage < 75 ? "low" : "safe"}>{subject.status}</strong>
                </div>
              </div>
            )
          })}
        </div>
      </section>
    )
  }
  const statusLabel = isConnecting
    ? displayText.connecting
    : isSpeaking
      ? displayText.speaking
      : isCallActive
        ? displayText.listening
        : displayText.idle

  const buttonTitle = isCallActive || isConnecting ? "Stop and close voice session" : displayText.openAssistant
  const buttonStateClass = isConnecting ? " connecting" : isCallActive ? " active" : ""
  const buttonBadge = isConnecting ? "Connecting" : isCallActive ? "Stop" : displayText.tapToAsk

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
                aria-pressed={key === voiceLanguage}
                disabled={isConnecting && key === voiceLanguage}
                onClick={() => handleLanguageButtonClick(key)}
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
            {renderAttendanceVisual(visualPayload)}
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
        className={`voice-assistant-btn${buttonStateClass}`}
        onClick={toggleVapi}
        title={buttonTitle}
        aria-label={buttonTitle}
      >
        <img src={gmuLogo} alt="GMU VoiceBot" className="voice-logo" />
      </button>
      {isOpen && <div className={`voice-action-badge${isCallActive || isConnecting ? " active" : ""}`}>{buttonBadge}</div>}
    </div>
  )
}

export default VoiceAssistant
