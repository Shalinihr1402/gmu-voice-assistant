import { useEffect, useRef, useState } from "react"
import { useNavigate } from "react-router-dom"
import Vapi from "@vapi-ai/web"
import gmuLogo from "../assets/gmu-logo.png"
import "./VoiceAssistant.css"
import { fetchJson, getBackendUrl } from "../utils/api"
import { getStoredUiLanguage, setStoredUiLanguage } from "../utils/uiLanguage"

const MAX_RECORDING_MS = 15000
const STREAMING_TIMESLICE_MS = 250
const LOCAL_SILENCE_THRESHOLD = 0.018
const LOCAL_SILENCE_MS = 350
const LOCAL_MIN_SPEECH_MS = 250
const USE_BROWSER_TTS_BY_DEFAULT = true
const USE_VAPI_AS_PRIMARY_VOICE = true
const VAPI_TOOL_NAME = "gmu_voice_assistant"
const RESULT_EXAM_OPTIONS = ["SEE", "RESIT", "RE-REGISTRATION"]
const RESULT_SEASON_OPTIONS = ["ODD", "EVEN"]

const createEmptyResultQuery = () => ({
  active: false,
  usn: "",
  semester: "",
  exam: "",
  year: "",
  season: ""
})

const createEmptySpeechRecoveryState = () => ({
  active: false,
  correctedText: "",
  displayText: ""
})

const VOICE_LANGUAGE_OPTIONS = {
  en: {
    label: "English",
    locale: "en-US",
    apiLanguage: "en",
    transcriptionLanguage: "en",
    voicePrefixes: ["en"],
    ttsProvider: "browser"
  },
  hi: {
    label: "Hindi",
    locale: "hi-IN",
    apiLanguage: "hi",
    transcriptionLanguage: "hi",
    voicePrefixes: ["hi"],
    ttsProvider: "browser"
  },
  kn: {
    label: "Kannada",
    locale: "kn-IN",
    apiLanguage: "kn",
    transcriptionLanguage: "kn",
    voicePrefixes: ["kn"],
    ttsProvider: "elevenlabs"
  }
}
const PREFERRED_FEMALE_VOICE_HINTS = [
  "zira",
  "aria",
  "jenny",
  "sonia",
  "neerja",
  "priya",
  "samantha",
  "victoria",
  "karen",
  "moira",
  "fiona",
  "ava",
  "thalia",
  "heera",
  "kalpana",
  "swara",
  "lekha",
  "female",
  "google hindi",
  "google हिन्दी"
]
const LIKELY_MALE_VOICE_HINTS = [
  "david",
  "mark",
  "hemant",
  "ravi",
  "alex",
  "daniel"
]
const SPEECH_RECOVERY_KEYWORDS = [
  "attendance",
  "result",
  "results",
  "marks",
  "marksheet",
  "semester",
  "exam",
  "season",
  "year",
  "profile",
  "payment",
  "fees",
  "fee",
  "balance",
  "registration",
  "certificate",
  "dashboard",
  "timetable",
  "schedule",
  "subject",
  "course",
  "receipt",
  "grievance",
  "backlog",
  "hallticket",
  "ticket",
  "usn",
  "sgpa",
  "cgpa",
  "see",
  "resit",
  "reregistration",
  "odd",
  "even",
  "dbms",
  "laboratory",
  "operating",
  "systems",
  "computer",
  "networks",
  "artificial",
  "intelligence",
  "software",
  "engineering",
  "timetable"
]
const SPEECH_RECOVERY_STOP_WORDS = new Set([
  "i",
  "me",
  "my",
  "mine",
  "you",
  "your",
  "the",
  "a",
  "an",
  "and",
  "or",
  "to",
  "for",
  "of",
  "in",
  "on",
  "at",
  "is",
  "are",
  "was",
  "were",
  "be",
  "do",
  "did",
  "does",
  "know",
  "about",
  "show",
  "tell",
  "check",
  "open",
  "view",
  "give",
  "want",
  "need",
  "please",
  "can",
  "could",
  "would",
  "what",
  "which",
  "when",
  "where",
  "how",
  "latest",
  "have",
  "has"
])
const SPEECH_RECOVERY_EXACT_REPLACEMENTS = [
  { pattern: /\bu\s*s\s*n\b/g, replacement: "usn" },
  { pattern: /\bd\s*b\s*m\s*s\b/g, replacement: "dbms" },
  { pattern: /\bo\s*s\b/g, replacement: "os" },
  { pattern: /\bc\s*n\b/g, replacement: "cn" },
  { pattern: /\ba\s*i\b/g, replacement: "ai" },
  { pattern: /\bs\s*e\s*e\b/g, replacement: "see" },
  { pattern: /\bre[\s-]*registration\b/g, replacement: "reregistration" },
  { pattern: /\bhall\s+ticket\b/g, replacement: "hallticket" },
  { pattern: /\btime\s+table\b/g, replacement: "timetable" }
]
const containsIndicScript = (text) => /[\u0900-\u097F\u0C80-\u0CFF]/u.test(String(text || ""))

const SPOKEN_TERM_REPLACEMENTS = [
  { pattern: /\bGMU\b/g, replacement: "G M U" },
  { pattern: /\bUSN\b/g, replacement: "U S N" },
  { pattern: /\bSGPA\b/g, replacement: "S G P A" },
  { pattern: /\bCGPA\b/g, replacement: "C G P A" },
  { pattern: /\bDBMS\b/g, replacement: "D B M S" },
  { pattern: /\bAI\b/g, replacement: "A I" },
  { pattern: /\bCN\b/g, replacement: "C N" },
  { pattern: /\bOS\b/g, replacement: "O S" },
  { pattern: /\bHOD\b/g, replacement: "H O D" },
  { pattern: /\bERP\b/g, replacement: "E R P" },
  { pattern: /\bSEE\b/g, replacement: "S E E" },
  { pattern: /\bRESIT\b/g, replacement: "re-sit" },
  { pattern: /\bRE-REGISTRATION\b/g, replacement: "re-registration" },
  { pattern: /\bODD\b/g, replacement: "odd" },
  { pattern: /\bEVEN\b/g, replacement: "even" }
]

const VoiceAssistant = () => {
  const [isActive, setIsActive] = useState(false)
  const [isListening, setIsListening] = useState(false)
  const [isProcessing, setIsProcessing] = useState(false)
  const [isSpeaking, setIsSpeaking] = useState(false)
  const [transcript, setTranscript] = useState("")
  const [response, setResponse] = useState("")
  const [suggestionText, setSuggestionText] = useState("")
  const [quickActions, setQuickActions] = useState([])
  const [replySource, setReplySource] = useState("")
  const [errorMessage, setErrorMessage] = useState("")
  const [currentUser, setCurrentUser] = useState(null)
  const [startupStatus, setStartupStatus] = useState("")
  const [voiceLanguage, setVoiceLanguage] = useState("en")
  const [isVapiCallActive, setIsVapiCallActive] = useState(false)
  const [vapiReady, setVapiReady] = useState(false)

  const audioRef = useRef(null)
  const vapiRef = useRef(null)
  const vapiConfigRef = useRef(null)
  const vapiCallActiveRef = useRef(false)
  const audioUrlRef = useRef(null)
  const recognitionRef = useRef(null)
  const recognitionTranscriptRef = useRef("")
  const recognitionFinalizedRef = useRef(false)
  const mediaRecorderRef = useRef(null)
  const streamRef = useRef(null)
  const listenTimeoutRef = useRef(null)
  const silenceTimeoutRef = useRef(null)
  const recordingAudioContextRef = useRef(null)
  const recordingAnalyserRef = useRef(null)
  const recordingSourceNodeRef = useRef(null)
  const recordingAnimationRef = useRef(null)
  const speechDetectedRef = useRef(false)
  const recordingStartedAtRef = useRef(0)
  const lastSpeechDetectedAtRef = useRef(0)
  const ignoreNextRecordingRef = useRef(false)
  const isSpeakingRef = useRef(false)
  const lastSpokenTextRef = useRef("")
  const isActiveRef = useRef(false)
  const isProcessingRef = useRef(false)
  const lastPageRef = useRef(null)
  const lastCommandRef = useRef("")
  const finalTranscriptRef = useRef("")
  const interimTranscriptRef = useRef("")
  const transcriptSubmittedRef = useRef(false)
  const streamClosedRef = useRef(false)
  const interruptStreamRef = useRef(null)
  const interruptAudioContextRef = useRef(null)
  const interruptAnalyserRef = useRef(null)
  const interruptSourceNodeRef = useRef(null)
  const interruptAnimationRef = useRef(null)
  const interruptSpeechFramesRef = useRef(0)
  const interruptInProgressRef = useRef(false)
  const profileCacheRef = useRef(null)
  const paymentCacheRef = useRef(null)
  const coursesCacheRef = useRef(null)
  const resultAvailabilityCacheRef = useRef(null)
  const pendingSpeechRecoveryRef = useRef(createEmptySpeechRecoveryState())
  const pendingResultQueryRef = useRef(createEmptyResultQuery())
  const pendingSuggestionActionRef = useRef(null)
  const immediateVapiCommandRef = useRef({ text: "", at: 0 })
  const navigationTimerRef = useRef(null)
  const lastAppliedToolResultRef = useRef({ key: "", at: 0 })
  const lastNavigationRef = useRef({ path: "", at: 0 })
  const lastResultReadySummaryRef = useRef({ summary: "", at: 0 })
  const navigationLockRef = useRef(false)
  const navigationLockTimerRef = useRef(null)

  const navigate = useNavigate()
  const languageConfig = VOICE_LANGUAGE_OPTIONS[voiceLanguage] || VOICE_LANGUAGE_OPTIONS.en
  const isHindiMode = voiceLanguage === "hi"
  const isKannadaMode = voiceLanguage === "kn"
  const shouldUseDeepgramStt = true
  const localizedText = {
    noAnswer: isHindiMode ? "मुझे इसका उत्तर नहीं मिला।" : "I could not find an answer.",
    serverError: isHindiMode ? "सर्वर जवाब नहीं दे रहा है। कृपया फिर से कोशिश करें।" : "Server is not responding. Please try again.",
    listening: isHindiMode ? "आपका सवाल सुन रहा हूं..." : "Listening for your question...",
    processing: isHindiMode ? "आपका सवाल प्रोसेस कर रहा हूं..." : "Processing your question...",
    didNotCatch: isHindiMode ? "मैं समझ नहीं पाया। Voice button दबाकर फिर से बोलिए।" : "I did not catch that. Tap the voice button and try again.",
    nextQuestion: isHindiMode ? "अगला सवाल पूछने के लिए voice button दबाइए।" : "Tap the voice button when you are ready with your next question.",
    micUnsupported: isHindiMode ? "इस browser में microphone recording supported नहीं है।" : "Microphone recording is not supported in this browser.",
    transcriptionError: isHindiMode ? "Streaming transcription शुरू नहीं हो पाया।" : "Unable to start streaming transcription.",
    emptyGreeting: isHindiMode ? "नमस्ते। मैं आपकी क्या मदद कर सकता हूं?" : "Hello. What can I help you with?",
    thinking: isHindiMode ? "सोच रहा हूं..." : "Thinking...",
    tapToAsk: isHindiMode ? "पूछने के लिए दबाएं" : "Tap to ask",
    speaking: isHindiMode ? "बोल रहा हूं..." : "Speaking...",
    listeningStatus: isHindiMode ? "सुन रहा हूं..." : "Listening...",
    hint: isHindiMode ? "अगला सवाल पूछने के लिए नीचे वाला round GMU button दबाइए।" : "Tap the round GMU button below to ask your next question.",
    badge: isHindiMode ? "पूछें" : "Tap to ask",
    openAssistant: isHindiMode ? "Voice assistant खोलें" : "Open voice assistant",
    askAria: isHindiMode ? "अपना सवाल पूछने के लिए दबाएं" : "Tap to ask your question",
    source: "Source:",
    status: isHindiMode ? "स्थिति:" : "Status:",
    you: isHindiMode ? "आप:" : "You:",
    assistant: "Assistant:",
    suggestion: "Suggestion:"
  }

  if (isKannadaMode) {
    localizedText.noAnswer = "ನನಗೆ ಉತ್ತರ ಸಿಗಲಿಲ್ಲ."
    localizedText.serverError = "ಸರ್ವರ್‌ನಿಂದ ಉತ್ತರ ಸಿಗುತ್ತಿಲ್ಲ. ದಯವಿಟ್ಟು ಮತ್ತೆ ಪ್ರಯತ್ನಿಸಿ."
    localizedText.listening = "ನಿಮ್ಮ ಪ್ರಶ್ನೆ ಕೇಳುತ್ತಿದ್ದೇನೆ..."
    localizedText.processing = "ನಿಮ್ಮ ಪ್ರಶ್ನೆಯನ್ನು ಸಂಸ್ಕರಿಸುತ್ತಿದ್ದೇನೆ..."
    localizedText.didNotCatch = "ನಾನು ಸರಿಯಾಗಿ ಕೇಳಲಿಲ್ಲ. ವಾಯ್ಸ್ ಬಟನ್ ಒತ್ತಿ ಮತ್ತೆ ಹೇಳಿ."
    localizedText.nextQuestion = "ಮುಂದಿನ ಪ್ರಶ್ನೆಗೆ ವಾಯ್ಸ್ ಬಟನ್ ಒತ್ತಿ."
    localizedText.micUnsupported = "ಈ ಬ್ರೌಸರ್‌ನಲ್ಲಿ ಮೈಕ್ರೋಫೋನ್ ರೆಕಾರ್ಡಿಂಗ್‌ಗೆ ಸಹಾಯ ಇಲ್ಲ."
    localizedText.transcriptionError = "ಸ್ಟ್ರೀಮಿಂಗ್ ಟ್ರಾನ್ಸ್‌ಕ್ರಿಪ್ಷನ್ ಪ್ರಾರಂಭವಾಗಲಿಲ್ಲ."
    localizedText.emptyGreeting = "ನಮಸ್ಕಾರ. ನಿಮಗೆ ಏನು ಸಹಾಯ ಬೇಕು?"
    localizedText.thinking = "ಯೋಚಿಸುತ್ತಿದ್ದೇನೆ..."
    localizedText.tapToAsk = "ಕೇಳಲು ಒತ್ತಿಸಿ"
    localizedText.speaking = "ಮಾತನಾಡುತ್ತಿದ್ದೇನೆ..."
    localizedText.listeningStatus = "ಕೇಳುತ್ತಿದ್ದೇನೆ..."
    localizedText.hint = "ಮುಂದಿನ ಪ್ರಶ್ನೆಗೆ ಮುಂದುವರಿಯಲು, ದಯವಿಟ್ಟು GMU ವೃತ್ತಾಕಾರದ ಬಟನ್ ಅನ್ನು ಒತ್ತುವಂತೆ ವಿನಂತಿಸುತ್ತೇನೆ."
    localizedText.badge = "ಕೇಳಿರಿ"
    localizedText.openAssistant = "ವಾಯ್ಸ್ ಅಸಿಸ್ಟೆಂಟ್ ತೆರೆಯಿರಿ"
    localizedText.askAria = "ನಿಮ್ಮ ಪ್ರಶ್ನೆ ಕೇಳಲು ಒತ್ತಿಸಿ"
    localizedText.status = "ಸ್ಥಿತಿ:"
    localizedText.you = "ನೀವು:"
    localizedText.suggestion = "Suggestion:"
  }

  const replyInSelectedLanguage = (english, hindi, kannada) => (
    isKannadaMode ? (kannada || english) : isHindiMode ? hindi : english
  )

  useEffect(() => {
    isActiveRef.current = isActive
  }, [isActive])

  useEffect(() => {
    isProcessingRef.current = isProcessing
  }, [isProcessing])

  useEffect(() => {
    setStoredUiLanguage(voiceLanguage)
  }, [voiceLanguage])

  useEffect(() => {
    vapiCallActiveRef.current = isVapiCallActive
  }, [isVapiCallActive])

  useEffect(() => {
    const handleResultReady = (event) => {
      const openedByVoiceBot = sessionStorage.getItem("voicebot_result_opened")
      if (openedByVoiceBot !== "true") return

      const summary = String(event.detail?.summary || "").trim()
      if (!summary) return

      const now = Date.now()
      if (lastResultReadySummaryRef.current.summary === summary && now - lastResultReadySummaryRef.current.at < 5000) {
        return
      }

      const semester = event.detail?.semester || ""
      const exam = event.detail?.exam || "SEE"
      const sgpa = event.detail?.sgpa || ""
      const localizedSummary = replyInSelectedLanguage(
        summary,
        `Aapka semester ${semester} ${exam} result open ho gaya hai. Aapka SGPA ${sgpa} hai.`,
        `Nimma semester ${semester} ${exam} result open agide. Nimma SGPA ${sgpa}.`
      )

      sessionStorage.removeItem("voicebot_result_opened")
      lastResultReadySummaryRef.current = { summary: localizedSummary, at: now }
      setResponse(localizedSummary)
      setReplySource("result_page")
      setSuggestionText("")
      setQuickActions([])

      if (!vapiCallActiveRef.current) {
        void speak(localizedSummary, { preferBrowser: true })
      }
    }

    window.addEventListener("gmu:result-ready", handleResultReady)
    return () => window.removeEventListener("gmu:result-ready", handleResultReady)
  }, [])

  useEffect(() => {
    fetchJson("getCurrentUser.php")
      .then((data) => {
        if (!data.error) {
          setCurrentUser(data)
        }
      })
      .catch(() => {})
  }, [])

  const getLanguageSwitchRequest = (text) => {
    const normalized = String(text || "").toLowerCase().replace(/[^a-z0-9\s]+/g, " ").replace(/\s+/g, " ").trim()
    const asksToSpeak = /\b(speak|speaak|speek|talk|baat|bath|batao|bolo|mathadu|maatadu|matadu|maathadu|matanadu|matanadi|reply|answer|language|mode)\b/.test(normalized)
      || /kannadadalli|hindiyalli|englishalli|hindi me|hindi mein|kannada me|kannada mein|english me|english mein/.test(normalized)
    if (!asksToSpeak) return null

    if (/\b(kannada|kannadadalli|kannada dalli|kanada|kannad)\b/.test(normalized)) {
      return { key: "kn", label: "Kannada", reply: "Sari, Kannada nalli mataduttene." }
    }
    if (/\b(hindi|hindiyalli|hindi me|hindi mein|hindi mai|hindhi)\b/.test(normalized)) {
      return { key: "hi", label: "Hindi", reply: "Theek hai, ab main Hindi mein baat karunga." }
    }
    if (/\b(english|englishalli|english me|english mein|inglish)\b/.test(normalized)) {
      return { key: "en", label: "English", reply: "Sure, I will continue in English." }
    }
    return null
  }

  const applyLanguageSwitch = (languageKey, reply) => {
    if (!VOICE_LANGUAGE_OPTIONS[languageKey]) return false
    setVoiceLanguage(languageKey)
    setResponse(reply)
    setSuggestionText("")
    setQuickActions([])
    setReplySource("language_switch")
    if (!vapiCallActiveRef.current) {
      setTimeout(() => {
        void speak(reply, { preferBrowser: true })
      }, 350)
    }
    return true
  }

  const getImmediateNavigationRequest = (text) => {
    const normalized = String(text || "").toLowerCase().replace(/[^a-z0-9\s]+/g, " ").replace(/\s+/g, " ").trim()
    const hasNavVerb = /\b(open|go|goto|navigate|show|take|visit|come back|back|return|kholo|khol|dikhao|jao|chalo|torisu|hogu|maadi|madi|tere|tereyiri)\b/.test(normalized)
    if (/\b(come back|go back|back to main|main page|home page|go home|return home|back home)\b/.test(normalized)) {
      return { path: "/home", page: "home", reply: "Returning to main page." }
    }
    if (!hasNavVerb && !/\b(page|portal|panna|puta)\b/.test(normalized)) return null
    if (/\b(registration|register)\b/.test(normalized)) return { path: "/registration", page: "registration", reply: "Opening registration page." }
    if (/\b(profile|profail)\b/.test(normalized)) return { path: "/profile", page: "profile", reply: "Opening profile page." }
    if (/\b(payment|fees payment|fee payment)\b/.test(normalized)) return { path: "/payment", page: "payment", reply: "Opening payment portal." }
    if (/\b(result|results|marks)\b/.test(normalized)) return { path: "/results", page: "results", reply: "Opening result page." }
    if (/\b(certificate|competency|digital competency)\b/.test(normalized)) return { path: "/certificate", page: "certificate", reply: "Opening certificate page." }
    if (/\b(dashboard|student dashboard)\b/.test(normalized)) return { path: "/dashboard", page: "dashboard", reply: "Opening dashboard." }
    if (/\b(home|portal)\b/.test(normalized)) return { path: normalized.includes("portal") ? "/portal" : "/home", page: normalized.includes("portal") ? "portal" : "home", reply: "Opening page." }
    return null
  }


  const runVoiceNavigation = (path, page, delayMs = 0) => {
    const targetPath = String(path || "").trim()
    if (!targetPath || navigationLockRef.current) return false

    const now = Date.now()
    const currentPath = `${window.location.pathname}${window.location.search}`
    const lastNavigation = lastNavigationRef.current

    if (lastNavigation.path === targetPath && now - lastNavigation.at < 3000) {
      return false
    }

    if (currentPath === targetPath) {
      lastPageRef.current = page || targetPath
      lastNavigationRef.current = { path: targetPath, at: now }
      return false
    }

    if (navigationTimerRef.current) {
      clearTimeout(navigationTimerRef.current)
      navigationTimerRef.current = null
    }

    const releaseNavigationLock = () => {
      navigationLockRef.current = false
      navigationLockTimerRef.current = null
    }

    const go = () => {
      navigationLockRef.current = true
      navigate(targetPath)
      lastPageRef.current = page || targetPath
      lastNavigationRef.current = { path: targetPath, at: Date.now() }
      immediateVapiCommandRef.current = { text: "", at: 0 }
      lastAppliedToolResultRef.current = { key: "", at: 0 }
      resetStreamingTranscript()
      setTranscript("")
      if (!vapiCallActiveRef.current) {
        cleanupRecorder()
      }
      if (navigationLockTimerRef.current) {
        clearTimeout(navigationLockTimerRef.current)
      }
      navigationLockTimerRef.current = setTimeout(releaseNavigationLock, 1200)
      navigationTimerRef.current = null
    }

    if (delayMs > 0) {
      navigationTimerRef.current = setTimeout(go, delayMs)
      return true
    }
    go()
    return true
  }
  const handleImmediateVapiUserCommand = (text) => {
    const normalized = String(text || "").toLowerCase().replace(/\s+/g, " ").trim()
    if (!normalized) return false

    const now = Date.now()
    if (immediateVapiCommandRef.current.text === normalized && now - immediateVapiCommandRef.current.at < 2500) {
      return true
    }

    immediateVapiCommandRef.current = { text: normalized, at: now }
    return false
  }

  const rememberVoicebotResultRequest = (path) => {
    const rawPath = String(path || "")
    if (!rawPath.startsWith("/results")) return

    try {
      const url = new URL(rawPath, window.location.origin)
      const semester = url.searchParams.get("semester") || ""
      const examType = url.searchParams.get("exam") || "SEE"
      const year = url.searchParams.get("year") || ""
      const season = url.searchParams.get("season") || ""
      const usn = url.searchParams.get("usn") || ""

      sessionStorage.setItem("voicebot_result_request", JSON.stringify({
        semester,
        examType,
        year,
        season,
        usn
      }))
    } catch {
      sessionStorage.setItem("voicebot_result_request", JSON.stringify({ examType: "SEE" }))
    }
  }
  const applyVapiToolResult = (result) => {
    if (!result || typeof result !== "object") return false

    const action = result.client_action || result.clientAction
    const resultKey = [
      result.intent || "",
      result.route || "",
      action?.type || "",
      action?.path || action?.language || "",
      result.reply || ""
    ].join("|")
    const now = Date.now()
    const lastApplied = lastAppliedToolResultRef.current
    if (lastApplied.key === resultKey && now - lastApplied.at < 2500) {
      return true
    }
    lastAppliedToolResultRef.current = { key: resultKey, at: now }

    let didApplyAction = false

    if (result.reply) {
      setResponse(result.reply)
    }
    if (result.suggestion) {
      setSuggestionText(result.suggestion)
    }
    if (Array.isArray(result.quick_actions)) {
      setQuickActions(result.quick_actions)
      rememberSuggestionFollowUp(result.quick_actions)
    }
    if (result.debug?.reply_source) {
      setReplySource(result.debug.reply_source)
    }

    if (action?.type === "set_language" && action.language) {
      didApplyAction = applyLanguageSwitch(action.language, result.reply || "Language changed. Please tap the voice button again.") || didApplyAction
    }
    if (action?.type === "navigate" && action.path) {
      rememberVoicebotResultRequest(action.path)
      runVoiceNavigation(action.path, action.page || action.path, 150)
      didApplyAction = true
    }

    return didApplyAction
  }

  const findVapiToolResults = (value, seen = new WeakSet()) => {
    if (!value || typeof value !== "object") return []
    if (seen.has(value)) return []
    seen.add(value)

    const results = []
    if (value.client_action || value.clientAction || value.reply || value.quick_actions) {
      results.push(value)
    }
    if (value.result && typeof value.result === "object") {
      results.push(...findVapiToolResults(value.result, seen))
    }
    if (value.toolCallResult && typeof value.toolCallResult === "object") {
      results.push(...findVapiToolResults(value.toolCallResult, seen))
    }
    if (Array.isArray(value.toolCallResults)) {
      value.toolCallResults.forEach((item) => results.push(...findVapiToolResults(item, seen)))
    }
    if (value.message && typeof value.message === "object") {
      results.push(...findVapiToolResults(value.message, seen))
    }
    if (Array.isArray(value.messages)) {
      value.messages.forEach((item) => results.push(...findVapiToolResults(item, seen)))
    }

    return results
  }

  const handleVapiMessage = (message) => {
    if (!message || typeof message !== "object") return

    const role = message.role || message.message?.role
    const text = message.transcript || message.text || message.message?.content || message.content || ""

    if (message.type === "transcript" && text) {
      const isFinalTranscript = !message.transcriptType || message.transcriptType === "final"
      if (role === "user") {
        setTranscript(text)
        if (isFinalTranscript) handleImmediateVapiUserCommand(text)
      } else if (role === "assistant") {
        setResponse(text)
      }
      return
    }

    if ((message.type === "conversation-update" || message.type === "message") && text) {
      if (role === "user") {
        setTranscript(text)
        handleImmediateVapiUserCommand(text)
      }
      if (role === "assistant") setResponse(text)
    }

    const toolResults = findVapiToolResults(message)
    const appliedResults = new Set()
    toolResults.forEach((result) => {
      if (appliedResults.has(result)) return
      appliedResults.add(result)
      applyVapiToolResult(result)
    })
  }

  const getOrCreateVapi = (publicKey) => {
    if (vapiRef.current) return vapiRef.current

    const vapi = new Vapi(publicKey)
    vapi.on("call-start", () => {
      setIsVapiCallActive(true)
      setVapiReady(true)
      setIsListening(true)
      setIsProcessing(false)
      setIsSpeaking(false)
      setStartupStatus(localizedText.listeningStatus)
    })
    vapi.on("call-end", () => {
      setIsVapiCallActive(false)
      setIsListening(false)
      setIsProcessing(false)
      setIsSpeaking(false)
      setStartupStatus(localizedText.tapToAsk)
    })
    vapi.on("speech-start", () => {
      setIsSpeaking(true)
      setIsListening(false)
    })
    vapi.on("speech-end", () => {
      setIsSpeaking(false)
      if (vapiCallActiveRef.current) setIsListening(true)
    })
    vapi.on("message", handleVapiMessage)
    vapi.on("error", (error) => {
      setErrorMessage(error?.message || "Vapi voice session failed.")
      setIsVapiCallActive(false)
      setIsListening(false)
      setIsProcessing(false)
      setIsSpeaking(false)
    })

    vapiRef.current = vapi
    return vapi
  }

  const loadVapiConfig = async () => {
    const config = await fetchJson("vapiConfig.php?language=multi")
    if (!config?.enabled || !config.public_key) {
      throw new Error(config?.setup_hint || "Vapi is not configured. Set VAPI_PUBLIC_KEY in backend/.env.")
    }
    vapiConfigRef.current = config
    return config
  }

  const startVapiCall = async () => {
    setIsActive(true)
    isActiveRef.current = true
    setErrorMessage("")
    setReplySource("vapi")
    setSuggestionText("")
    setQuickActions([])
    setResponse(localizedText.listening)
    setStartupStatus(localizedText.listeningStatus)
    setIsProcessing(true)

    const config = await loadVapiConfig()
    const vapi = getOrCreateVapi(config.public_key)

    await vapi.start(config.assistant, config.assistant_overrides || {})
  }

  const stopVapiCall = () => {
    if (vapiRef.current) {
      vapiRef.current.stop()
    }
    setIsVapiCallActive(false)
    setIsListening(false)
    setIsProcessing(false)
    setIsSpeaking(false)
    setStartupStatus(localizedText.tapToAsk)
  }

  const toggleVapiCall = async () => {
    try {
      if (vapiCallActiveRef.current) {
        stopVapiCall()
        return
      }
      await startVapiCall()
    } catch (error) {
      setErrorMessage(error?.message || "Unable to start Vapi voice assistant.")
      setIsProcessing(false)
      setIsListening(false)
      setIsSpeaking(false)
      setStartupStatus(localizedText.tapToAsk)
    }
  }

  const cleanupRecorder = (options = {}) => {
    const { ignoreTranscript = false } = options

    if (recognitionRef.current) {
      const recognition = recognitionRef.current
      recognitionRef.current = null
      try {
        recognition.onresult = null
        recognition.onerror = null
        recognition.onend = null
        recognition.stop()
      } catch {}
    }

    recognitionTranscriptRef.current = ""
    recognitionFinalizedRef.current = false

    if (listenTimeoutRef.current) {
      clearTimeout(listenTimeoutRef.current)
      listenTimeoutRef.current = null
    }

    if (silenceTimeoutRef.current) {
      clearTimeout(silenceTimeoutRef.current)
      silenceTimeoutRef.current = null
    }

    if (recordingAnimationRef.current) {
      cancelAnimationFrame(recordingAnimationRef.current)
      recordingAnimationRef.current = null
    }

    if (recordingSourceNodeRef.current) {
      try {
        recordingSourceNodeRef.current.disconnect()
      } catch {}
      recordingSourceNodeRef.current = null
    }

    if (recordingAnalyserRef.current) {
      try {
        recordingAnalyserRef.current.disconnect()
      } catch {}
      recordingAnalyserRef.current = null
    }

    if (recordingAudioContextRef.current) {
      const context = recordingAudioContextRef.current
      recordingAudioContextRef.current = null
      void context.close().catch(() => {})
    }

    speechDetectedRef.current = false
    recordingStartedAtRef.current = 0
    lastSpeechDetectedAtRef.current = 0

    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== "inactive") {
      if (ignoreTranscript) {
        ignoreNextRecordingRef.current = true
      }
      mediaRecorderRef.current.stop()
    }

    mediaRecorderRef.current = null

    if (streamRef.current) {
      streamRef.current.getTracks().forEach((track) => track.stop())
      streamRef.current = null
    }

    setIsListening(false)
  }

  const cleanupAudio = () => {
    if (audioRef.current) {
      audioRef.current.pause()
      audioRef.current.src = ""
      audioRef.current = null
    }

    if (audioUrlRef.current) {
      URL.revokeObjectURL(audioUrlRef.current)
      audioUrlRef.current = null
    }
  }

  const stopInterruptMonitor = async () => {
    interruptSpeechFramesRef.current = 0

    if (interruptAnimationRef.current) {
      cancelAnimationFrame(interruptAnimationRef.current)
      interruptAnimationRef.current = null
    }

    if (interruptSourceNodeRef.current) {
      try {
        interruptSourceNodeRef.current.disconnect()
      } catch {}
      interruptSourceNodeRef.current = null
    }

    if (interruptAnalyserRef.current) {
      try {
        interruptAnalyserRef.current.disconnect()
      } catch {}
      interruptAnalyserRef.current = null
    }

    if (interruptAudioContextRef.current) {
      const context = interruptAudioContextRef.current
      interruptAudioContextRef.current = null
      try {
        await context.close()
      } catch {}
    }

    if (interruptStreamRef.current) {
      interruptStreamRef.current.getTracks().forEach((track) => track.stop())
      interruptStreamRef.current = null
    }
  }

  const handleSpeechInterrupt = async () => {
    if (interruptInProgressRef.current || !isActiveRef.current || isListening || isProcessingRef.current || !isSpeakingRef.current) {
      return
    }

    interruptInProgressRef.current = true

    try {
      await stopCurrentSpeech()
      setResponse(localizedText.listening)
      setReplySource("")
      await startListening()
    } finally {
      interruptInProgressRef.current = false
    }
  }

  const startInterruptMonitor = async () => {
    if (
      interruptStreamRef.current ||
      interruptAnimationRef.current ||
      !isActiveRef.current ||
      isListening ||
      isProcessingRef.current ||
      !isSpeakingRef.current
    ) {
      return
    }

    if (!navigator.mediaDevices?.getUserMedia) {
      return
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          channelCount: 1,
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        }
      })

      const AudioContextClass = window.AudioContext || window.webkitAudioContext
      if (!AudioContextClass) {
        stream.getTracks().forEach((track) => track.stop())
        return
      }

      const audioContext = new AudioContextClass()
      const analyser = audioContext.createAnalyser()
      const sourceNode = audioContext.createMediaStreamSource(stream)

      analyser.fftSize = 2048
      sourceNode.connect(analyser)

      interruptStreamRef.current = stream
      interruptAudioContextRef.current = audioContext
      interruptAnalyserRef.current = analyser
      interruptSourceNodeRef.current = sourceNode

      const timeData = new Uint8Array(analyser.fftSize)

      const monitor = () => {
        if (!interruptAnalyserRef.current || !isSpeakingRef.current || isListening || isProcessingRef.current) {
          interruptAnimationRef.current = null
          return
        }

        analyser.getByteTimeDomainData(timeData)

        let sumSquares = 0
        for (let index = 0; index < timeData.length; index += 1) {
          const normalized = (timeData[index] - 128) / 128
          sumSquares += normalized * normalized
        }

        const rms = Math.sqrt(sumSquares / timeData.length)
        if (rms >= INTERRUPT_SPEECH_THRESHOLD) {
          interruptSpeechFramesRef.current += 1
        } else {
          interruptSpeechFramesRef.current = 0
        }

        if (interruptSpeechFramesRef.current >= INTERRUPT_MIN_FRAMES) {
          interruptAnimationRef.current = null
          void handleSpeechInterrupt()
          return
        }

        interruptAnimationRef.current = requestAnimationFrame(monitor)
      }

      interruptAnimationRef.current = requestAnimationFrame(monitor)
    } catch {
      // Best-effort barge-in. If mic access fails here, normal playback continues.
    }
  }

  const stopStreamingTts = async ({ clearRemote = true } = {}) => {
    void clearRemote
    await stopInterruptMonitor()
    finishSpeaking()
  }

  const finishSpeaking = () => {
    isSpeakingRef.current = false
    setIsSpeaking(false)
    setStartupStatus("")
    void stopInterruptMonitor()
  }

  const stopCurrentSpeech = async () => {
    isSpeakingRef.current = false
    setIsSpeaking(false)
    await stopInterruptMonitor()
    cleanupAudio()
    window.speechSynthesis.cancel()
    setStartupStatus("")
  }

  const resetStreamingTranscript = () => {
    finalTranscriptRef.current = ""
    interimTranscriptRef.current = ""
    transcriptSubmittedRef.current = false
    streamClosedRef.current = false
  }

  const getCombinedTranscript = () => (
    `${finalTranscriptRef.current} ${interimTranscriptRef.current}`.replace(/\s+/g, " ").trim()
  )

  const getSpeechRecognitionClass = () => (
    window.SpeechRecognition || window.webkitSpeechRecognition || null
  )

  const submitStreamingTranscript = async (text) => {
    const cleanedText = (text || "").trim()
    const commandText = containsIndicScript(cleanedText)
      ? cleanedText
      : cleanedText.toLowerCase()

    if (!commandText || transcriptSubmittedRef.current || !isActiveRef.current) {
      return
    }

    transcriptSubmittedRef.current = true
    setTranscript(commandText)
    await handleVoiceCommand(commandText)
  }

  const appendFinalTranscript = (nextText) => {
    const normalizedNext = (nextText || "").trim()
    if (!normalizedNext) {
      return
    }

    const normalizedCurrent = finalTranscriptRef.current.trim()
    if (!normalizedCurrent) {
      finalTranscriptRef.current = normalizedNext
      return
    }

    if (normalizedCurrent === normalizedNext || normalizedCurrent.endsWith(normalizedNext)) {
      return
    }

    finalTranscriptRef.current = `${normalizedCurrent} ${normalizedNext}`.replace(/\s+/g, " ").trim()
  }

  const speakTextStream = async (textOrStream, options = {}) => {
    const { preferBrowser = USE_BROWSER_TTS_BY_DEFAULT } = options
    const bufferedText = typeof textOrStream === "string" ? textOrStream : ""
    const speechText = prepareSpeechText(bufferedText)
    const shouldUseBrowserTts = preferBrowser || languageConfig.ttsProvider === "browser"

    if (shouldUseBrowserTts) {
      if (speechText) {
        speakWithBrowserFallback(speechText)
      }
      return
    }

    if (!speechText) {
      finishSpeaking()
      return
    }

    const playElevenLabsBlobAudio = async () => {
      const response = await fetch(getBackendUrl("elevenlabsTts.php"), {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          text: speechText,
          language: languageConfig.apiLanguage
        })
      })

      if (!response.ok) {
        const text = await response.text()
        let message = "Unable to synthesize speech."

        if (text) {
          try {
            const data = JSON.parse(text)
            message = data?.error || message
          } catch {}
        }

        throw new Error(message)
      }

      const audioBlob = await response.blob()
      const audioUrl = URL.createObjectURL(audioBlob)
      audioUrlRef.current = audioUrl

      const audio = new Audio(audioUrl)
      audioRef.current = audio
      audio.onended = finishSpeaking
      audio.onerror = finishSpeaking
      await audio.play()
    }

    try {
      void stopInterruptMonitor()
      cleanupAudio()
      cleanupRecorder({ ignoreTranscript: true })
      isSpeakingRef.current = true
      setIsSpeaking(true)
      setStartupStatus("")
      lastSpokenTextRef.current = speechText

      await playElevenLabsBlobAudio()
    } catch (error) {
      if (isKannadaMode) {
        finishSpeaking()
        setErrorMessage(error?.message || "Kannada speech synthesis is unavailable right now.")
        return
      }

      speakWithBrowserFallback(speechText)
    }
  }

  const normalizeText = (text) => {
    const value = String(text || "").trim()
    if (!value) {
      return ""
    }

    const lowercased = containsIndicScript(value) ? value : value.toLowerCase()

    return lowercased
      .replace(/[^\p{L}\p{N}\s]/gu, " ")
      .replace(/\s+/g, " ")
      .replace(/^(then|and|so|okay|ok|now|please)\s+/u, "")
      .trim()
  }

  const looksLikeActionableCommand = (text) => (
    /\b(open|go|navigate|show|check|tell|view|display|pay|download|track|apply|search|see|bring|take|latest|fee|fees|balance|result|payment|receipt|grievance|profile|dashboard|registration|certificate|attendance|backlog|semester|usn|course|subject|hall ticket|cgpa|sgpa)\b/.test(text)
  )

const spellTokenForSpeech = (token) => (
  String(token || "")
      .replace(/-/g, " ")
      .split("")
      .filter(Boolean)
      .join(" ")
      .replace(/\s+/g, " ")
      .trim()
  )

  const normalizeAcademicYearInput = (text) => {
    const value = String(text || "").toLowerCase().trim()
    if (!value) {
      return ""
    }

    const directYearRange = value.match(/\b(20\d{2})\s*(?:-|to|and|\s)\s*((?:20)?\d{2})\b/)
    if (directYearRange) {
      return `${directYearRange[1]}-${String(directYearRange[2]).slice(-2)}`
    }

    const sixDigitCompactYear = value.match(/\b(20\d{2})(\d{2})\b/)
    if (sixDigitCompactYear) {
      return `${sixDigitCompactYear[1]}-${sixDigitCompactYear[2]}`
    }

    const splitCompactYear = value.match(/\b(20\d{2})\s+(20)\s*(\d{2})\b/)
    if (splitCompactYear) {
      return `${splitCompactYear[1]}-${splitCompactYear[3]}`
    }

    const allYearLikeParts = value.match(/20\d{2}|\b\d{2}\b/g) || []
    if (allYearLikeParts.length >= 2 && /^20\d{2}$/.test(allYearLikeParts[0])) {
      return `${allYearLikeParts[0]}-${String(allYearLikeParts[1]).slice(-2)}`
    }

    return ""
  }

  const prepareSpeechText = (text) => {
    let prepared = String(text || "").trim()
    if (!prepared) {
      return ""
    }

    prepared = prepared
      .replace(/\b(?:Rs\.?|INR)\s*([0-9][0-9,]*(?:\.\d+)?)/gi, "rupees $1")
      .replace(/\u{20B9}\s*([0-9][0-9,]*(?:\.\d+)?)/gu, "rupees $1")
      .replace(/\s+/g, " ")
      .replace(/\s*[:;]\s*/g, ", ")
      .replace(/\s*[|/]\s*/g, " or ")
      .replace(/\(([^)]+)\)/g, ", $1, ")
      .replace(/\s*-\s*/g, " ")
      .replace(/([a-z])([A-Z])/g, "$1, $2")
      .replace(/([0-9])([A-Za-z])/g, "$1 $2")
      .replace(/([A-Za-z])([0-9])/g, "$1 $2")
      .replace(/\b1st\b/gi, "first")
      .replace(/\b2nd\b/gi, "second")
      .replace(/\b3rd\b/gi, "third")
      .replace(/\b4th\b/gi, "fourth")
      .replace(/\b5th\b/gi, "fifth")
      .replace(/\b6th\b/gi, "sixth")
      .replace(/\b7th\b/gi, "seventh")
      .replace(/\b8th\b/gi, "eighth")

    SPOKEN_TERM_REPLACEMENTS.forEach(({ pattern, replacement }) => {
      prepared = prepared.replace(pattern, replacement)
    })

    prepared = prepared.replace(/\b([A-Z]{2,}[0-9][A-Z0-9-]*)\b/g, (token) => spellTokenForSpeech(token))
    prepared = prepared.replace(/\b([A-Z]{2,})\b/g, (token) => {
      if (token.length > 6) {
        return token
      }

      return spellTokenForSpeech(token)
    })

    prepared = prepared
      .replace(/\s*,\s*,+/g, ", ")
      .replace(/\s+\./g, ".")
      .replace(/\s+,/g, ",")
      .replace(/([.!?])\s*(and|then)\s+/gi, "$1 $2 ")
      .replace(/\s+/g, " ")
      .trim()

    return prepared
  }

  const isSelfTranscript = (text) => {
    const transcriptText = normalizeText(text)
    const spokenText = normalizeText(lastSpokenTextRef.current)

    if (!transcriptText || !spokenText) {
      return false
    }

    if (transcriptText === spokenText) {
      return true
    }

    if (containsIndicScript(text) || containsIndicScript(lastSpokenTextRef.current)) {
      return false
    }

    const transcriptWords = transcriptText.split(" ").filter(Boolean)

    // Keep very short slot answers like "c" or "4th" available to the guided voice flow.
    if (transcriptText.length <= 2 || transcriptWords.length <= 1) {
      return false
    }

    if (looksLikeActionableCommand(transcriptText) && transcriptWords.length >= 3) {
      return false
    }

    if (spokenText.includes(transcriptText) || transcriptText.includes(spokenText)) {
      return true
    }

    if (transcriptWords.length < 4) {
      return false
    }

    const spokenWords = new Set(spokenText.split(" ").filter(Boolean))
    const overlap = transcriptWords.filter((word) => spokenWords.has(word)).length

    return overlap / transcriptWords.length >= 0.7
  }

  const startListening = async () => {
    if (!isActiveRef.current || isListening || isProcessingRef.current || isSpeakingRef.current) {
      return
    }

    setStartupStatus("")

    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
      const SpeechRecognitionClass = getSpeechRecognitionClass()
      if (shouldUseDeepgramStt || !SpeechRecognitionClass) {
        setErrorMessage(localizedText.micUnsupported)
        return
      }
    }

    try {
      resetStreamingTranscript()
      const SpeechRecognitionClass = getSpeechRecognitionClass()

      if (SpeechRecognitionClass && !shouldUseDeepgramStt) {
        const recognition = new SpeechRecognitionClass()
        recognitionRef.current = recognition
        recognitionTranscriptRef.current = ""
        recognitionFinalizedRef.current = false

        recognition.lang = languageConfig.locale
        recognition.continuous = false
        recognition.interimResults = true
        if ("maxAlternatives" in recognition) {
          recognition.maxAlternatives = 1
        }

        recognition.onresult = (event) => {
          let combinedTranscript = ""

          for (let index = event.resultIndex; index < event.results.length; index += 1) {
            const result = event.results[index]
            const nextText = (result?.[0]?.transcript || "").trim()
            if (!nextText) {
              continue
            }

            combinedTranscript = `${combinedTranscript} ${nextText}`.trim()
          }

          if (!combinedTranscript) {
            return
          }

          recognitionTranscriptRef.current = combinedTranscript
          setTranscript(
            containsIndicScript(combinedTranscript)
              ? combinedTranscript
              : combinedTranscript.toLowerCase()
          )

          const lastResult = event.results[event.results.length - 1]
          if (lastResult?.isFinal) {
            recognitionFinalizedRef.current = true
          }
        }

        recognition.onerror = () => {
          recognitionRef.current = null
          setIsListening(false)
        }

        recognition.onend = async () => {
          if (recognitionRef.current === recognition) {
            recognitionRef.current = null
          }

          setIsListening(false)

          if (!isActiveRef.current) {
            return
          }

          const finalText = (recognitionTranscriptRef.current || "").trim()
          recognitionTranscriptRef.current = ""

          if (!finalText) {
            setTranscript("")
            setResponse(localizedText.didNotCatch)
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          setResponse(localizedText.processing)
          setReplySource("")
          setIsProcessing(true)

          if (isSelfTranscript(finalText)) {
            setIsProcessing(false)
            setTranscript(
              containsIndicScript(finalText) ? finalText : finalText.toLowerCase()
            )
            setResponse(localizedText.nextQuestion)
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          await submitStreamingTranscript(finalText)
        }

        setErrorMessage("")
        setIsListening(true)
        setTranscript("")
        recognition.start()
        return
      }

      const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          channelCount: 1,
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        }
      })

      const mimeType = MediaRecorder.isTypeSupported("audio/webm;codecs=opus")
        ? "audio/webm;codecs=opus"
        : ""

      const recorder = mimeType
        ? new MediaRecorder(stream, { mimeType })
        : new MediaRecorder(stream)

      const chunks = []
      const AudioContextClass = window.AudioContext || window.webkitAudioContext

      if (AudioContextClass) {
        const audioContext = new AudioContextClass()
        const analyser = audioContext.createAnalyser()
        const sourceNode = audioContext.createMediaStreamSource(stream)
        const timeData = new Uint8Array(2048)

        analyser.fftSize = 2048
        sourceNode.connect(analyser)

        recordingAudioContextRef.current = audioContext
        recordingAnalyserRef.current = analyser
        recordingSourceNodeRef.current = sourceNode

        const monitorSilence = () => {
          const activeRecorder = mediaRecorderRef.current
          if (!activeRecorder || activeRecorder.state === "inactive" || !recordingAnalyserRef.current) {
            recordingAnimationRef.current = null
            return
          }

          analyser.getByteTimeDomainData(timeData)

          let sumSquares = 0
          for (let index = 0; index < timeData.length; index += 1) {
            const normalized = (timeData[index] - 128) / 128
            sumSquares += normalized * normalized
          }

          const rms = Math.sqrt(sumSquares / timeData.length)
          if (rms >= LOCAL_SILENCE_THRESHOLD) {
            speechDetectedRef.current = true
            lastSpeechDetectedAtRef.current = Date.now()
            if (silenceTimeoutRef.current) {
              clearTimeout(silenceTimeoutRef.current)
              silenceTimeoutRef.current = null
            }
          } else if (
            speechDetectedRef.current &&
            !silenceTimeoutRef.current &&
            lastSpeechDetectedAtRef.current > 0 &&
            lastSpeechDetectedAtRef.current - recordingStartedAtRef.current >= LOCAL_MIN_SPEECH_MS
          ) {
            silenceTimeoutRef.current = window.setTimeout(() => {
              silenceTimeoutRef.current = null
              if (mediaRecorderRef.current?.state !== "inactive") {
                mediaRecorderRef.current.stop()
              }
            }, LOCAL_SILENCE_MS)
          }

          recordingAnimationRef.current = requestAnimationFrame(monitorSilence)
        }

        recordingAnimationRef.current = requestAnimationFrame(monitorSilence)
      }

      recorder.ondataavailable = (event) => {
        if (event.data.size) {
          chunks.push(event.data)
        }
      }

      recorder.onstop = async () => {
        cleanupRecorder()

        if (ignoreNextRecordingRef.current) {
          ignoreNextRecordingRef.current = false
          return
        }

        if (!chunks.length || !isActiveRef.current) {
          return
        }

        setResponse(localizedText.processing)
        setReplySource("")
        setIsProcessing(true)

        const audioBlob = new Blob(chunks, {
          type: mimeType || "audio/webm"
        })

        if (audioBlob.size < 2048) {
          setIsProcessing(false)
          setTranscript("")
          setResponse(localizedText.didNotCatch)
          setReplySource("")
          lastCommandRef.current = ""
          return
        }

        const formData = new FormData()
        formData.append("audio", audioBlob, "voice-input.webm")
        formData.append("language", languageConfig.transcriptionLanguage)

        try {
          const data = await fetchJson("deepgramTranscribe.php", {
            method: "POST",
            body: formData
          })

          const finalText = (data?.transcript || "").trim()
          if (!finalText) {
            setIsProcessing(false)
            setTranscript("")
            setResponse(localizedText.didNotCatch)
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          if (isSelfTranscript(finalText)) {
            setIsProcessing(false)
            setTranscript(
              containsIndicScript(finalText) ? finalText : finalText.toLowerCase()
            )
            setResponse(localizedText.nextQuestion)
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          await submitStreamingTranscript(finalText)
        } catch (transcriptionError) {
          setIsProcessing(false)
          setErrorMessage(transcriptionError.message || "Unable to transcribe your audio.")
        }
      }

      streamRef.current = stream
      mediaRecorderRef.current = recorder
      recordingStartedAtRef.current = Date.now()
      setErrorMessage("")
      setIsListening(true)
      setTranscript("")
      recorder.start(STREAMING_TIMESLICE_MS)

      listenTimeoutRef.current = setTimeout(() => {
        if (recorder.state !== "inactive") {
          recorder.stop()
        }
      }, MAX_RECORDING_MS)
    } catch (error) {
      cleanupRecorder()
      setErrorMessage(error.message || localizedText.transcriptionError)
    }
  }

  const speakWithBrowserFallback = (text) => {
    if (!("speechSynthesis" in window)) {
      finishSpeaking()
      return
    }

    void stopInterruptMonitor()
    cleanupAudio()
    cleanupRecorder({ ignoreTranscript: true })
    isSpeakingRef.current = false
    setIsSpeaking(false)
    window.speechSynthesis.cancel()

    const utterance = new SpeechSynthesisUtterance(prepareSpeechText(text))
    const voices = window.speechSynthesis.getVoices()
    const voiceMatchesLanguage = (voice) => (
      languageConfig.voicePrefixes.some((prefix) => String(voice.lang || "").toLowerCase().startsWith(prefix))
    )
    const voiceLooksFemale = (voice) => {
      const voiceLabel = `${voice.name} ${voice.voiceURI}`.toLowerCase()
      return PREFERRED_FEMALE_VOICE_HINTS.some((hint) => voiceLabel.includes(hint))
    }
    const voiceLooksMale = (voice) => {
      const voiceLabel = `${voice.name} ${voice.voiceURI}`.toLowerCase()
      return LIKELY_MALE_VOICE_HINTS.some((hint) => voiceLabel.includes(hint))
    }
    const languageVoices = voices.filter(voiceMatchesLanguage)
    const preferredVoice = languageVoices.find(voiceLooksFemale)
      || voices.find(voiceLooksFemale)
      || languageVoices.find((voice) => !voiceLooksMale(voice))
      || languageVoices[0]

    if (preferredVoice) {
      utterance.voice = preferredVoice
    }

    utterance.lang = languageConfig.locale
    utterance.rate = isHindiMode ? 1.01 : 1.04
    utterance.pitch = isHindiMode ? 1.08 : 1.1
    utterance.volume = 1

    utterance.onstart = () => {
      isSpeakingRef.current = true
      setIsSpeaking(true)
    }

    utterance.onend = finishSpeaking

    utterance.onerror = finishSpeaking

    lastSpokenTextRef.current = utterance.text
    window.speechSynthesis.speak(utterance)
  }

  const speak = async (text, options = {}) => {
    const { preferBrowser = languageConfig.ttsProvider !== "elevenlabs" && USE_BROWSER_TTS_BY_DEFAULT } = options

    if (!text) return

    await speakTextStream(text, { preferBrowser })
  }

  const askAI = async (text) => {
    try {
      const data = await fetchJson("api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: text, language: languageConfig.apiLanguage })
      })
      setReplySource(data.reply_source || "unknown")
      return {
        reply: data.reply || localizedText.noAnswer,
        suggestion: data.suggestion || "",
        quickActions: Array.isArray(data.quick_actions) ? data.quick_actions : []
      }
    } catch {
      setReplySource("request_failed")
      return {
        reply: localizedText.serverError,
        suggestion: "",
        quickActions: []
      }
    }
  }

  const replyImmediately = (text) => {
    const localSuggestion = buildLocalSuggestionPayload(text)
    const speechText = localSuggestion?.suggestion ? `${text} ${localSuggestion.suggestion}` : text

    setResponse(text)
    const actions = localSuggestion?.quickActions || []
    setSuggestionText(localSuggestion?.suggestion || "")
    setQuickActions(actions)
    rememberSuggestionFollowUp(actions)
    setReplySource("")
    void speak(speechText, { preferBrowser: languageConfig.ttsProvider !== "elevenlabs" })
    lastCommandRef.current = ""
  }

  const presentAssistantReply = (payload) => {
    const reply = payload?.reply || localizedText.noAnswer
    const suggestion = payload?.suggestion || ""
    const actions = Array.isArray(payload?.quickActions) ? payload.quickActions : []
    const speechText = suggestion ? `${reply} ${suggestion}` : reply

    setResponse(reply)
    setSuggestionText(suggestion)
    setQuickActions(actions)
    rememberSuggestionFollowUp(actions)
    void speak(speechText)
    lastCommandRef.current = ""
  }

  const buildLocalSuggestionPayload = (replyText) => {
    const normalized = (replyText || "").toLowerCase()

    if (!normalized) {
      return null
    }

    if ((normalized.includes("pending fee") || normalized.includes("outstanding fee") || normalized.includes("remaining balance") || normalized.includes("pending balance")) && !normalized.includes("no pending")) {
      return {
        suggestion: replyInSelectedLanguage(
          "Do you want to open the payment portal or check the fee breakup?",
          "क्या आप payment portal खोलना चाहते हैं या fee breakup देखना चाहते हैं?",
          "Payment portal ತೆರೆಯಬೇಕೆ ಅಥವಾ fee breakup ನೋಡಬೇಕೆ?"
        ),
        quickActions: [
          {
            label: replyInSelectedLanguage("Fee breakup", "Fee breakup", "Fee breakup"),
            prompt: replyInSelectedLanguage("Show my fee breakup", "मेरा fee breakup दिखाइए", "ನನ್ನ fee breakup ತೋರಿಸಿ")
          },
          {
            label: replyInSelectedLanguage("Open payment", "Payment खोलें", "Payment ತೆರೆಯಿರಿ"),
            prompt: replyInSelectedLanguage("Open payment portal", "Payment portal खोलिए", "Payment portal ತೆರೆಯಿರಿ")
          }
        ]
      }
    }

    if (normalized.includes("registration is still pending") || normalized.includes("final registration is still pending")) {
      return {
        suggestion: replyInSelectedLanguage(
          "Do you want to check the exact pending fee amount?",
          "क्या आप exact pending fee amount देखना चाहते हैं?",
          "Exact pending fee amount ನೋಡಬೇಕೆ?"
        ),
        quickActions: [
          {
            label: replyInSelectedLanguage("Check fees", "Fees देखें", "Fees ನೋಡಿ"),
            prompt: replyInSelectedLanguage("What is my fee balance", "मेरी fee balance क्या है", "ನನ್ನ fee balance ಎಷ್ಟು")
          }
        ]
      }
    }

    if (normalized.includes("backlog")) {
      return {
        suggestion: replyInSelectedLanguage(
          "Do you want to check your latest result details too?",
          "क्या आप latest result details भी देखना चाहते हैं?",
          "Latest result details ಕೂಡ ನೋಡಬೇಕೆ?"
        ),
        quickActions: [
          {
            label: replyInSelectedLanguage("Latest result", "Latest result", "Latest result"),
            prompt: replyInSelectedLanguage("Show my latest result", "मेरा latest result दिखाइए", "ನನ್ನ latest result ತೋರಿಸಿ")
          }
        ]
      }
    }

    return null
  }

  const getOrdinalLabel = (value) => {
    const number = Number(value)

    if (!Number.isFinite(number) || number <= 0) {
      return ""
    }

    const mod100 = number % 100
    if (mod100 >= 11 && mod100 <= 13) {
      return `${number}th`
    }

    switch (number % 10) {
      case 1:
        return `${number}st`
      case 2:
        return `${number}nd`
      case 3:
        return `${number}rd`
      default:
        return `${number}th`
    }
  }

  const getLocalProfileReply = (text) => {
    if (isKannadaMode) {
      return ""
    }

    if (currentUser?.role_key !== "student") {
      return ""
    }

    const fullName = currentUser?.full_name || ""
    const branch = currentUser?.branch_name || currentUser?.unit_name || ""
    const semesterLabel = getOrdinalLabel(currentUser?.semester)

    if (/\b(which|what)\s+semester\b|\bmy semester\b|सेमेस्टर|semester/.test(text)) {
      return currentUser?.semester
        ? replyInSelectedLanguage(
          `You are currently in semester ${currentUser.semester}.`,
          `आप अभी सेमेस्टर ${currentUser.semester} में हैं।`
        )
        : ""
    }

    if (/\b(which|what)\s+(department|branch)\b|\bmy department\b|\bmy branch\b|विभाग|ब्रांच|डिपार्टमेंट|branch/.test(text)) {
      return branch
        ? replyInSelectedLanguage(
          `You are from the ${branch} department.`,
          `आप ${branch} विभाग से हैं।`
        )
        : ""
    }

    if (/\b(who am i|do you know who i am|do you know about me|know about me|tell me about me|my profile|tell me about my profile|what am i studying)\b|मेरे बारे|मैं कौन|मेरी प्रोफाइल|मेरा प्रोफाइल/.test(text)) {
      if (fullName && currentUser?.semester && branch) {
        return replyInSelectedLanguage(
          `You are ${fullName}, a semester ${currentUser.semester} ${branch} student at GM University. How can I help you today?`,
          `आप ${fullName} हैं, GM University में सेमेस्टर ${currentUser.semester} के ${branch} छात्र। मैं आपकी क्या मदद कर सकता हूं?`
        )
      }

      if (fullName && branch) {
        return replyInSelectedLanguage(
          `You are ${fullName} from the ${branch} department at GM University. How can I help you today?`,
          `आप ${fullName} हैं और GM University में ${branch} विभाग से हैं। मैं आपकी क्या मदद कर सकता हूं?`
        )
      }
    }

    return ""
  }

  const getFastNaturalReply = (text) => {
    if (isKannadaMode) {
      return ""
    }

    const normalized = (text || "").trim().toLowerCase()
    const fullName = currentUser?.full_name || ""
    const firstName = fullName.trim().split(/\s+/)[0] || ""
    const branch = currentUser?.branch_name || currentUser?.unit_name || ""
    const semesterLabel = getOrdinalLabel(currentUser?.semester)

    if (/\b(do you know about me|know about me|tell me about me)\b|मेरे बारे|मेरी प्रोफाइल|मेरा प्रोफाइल/.test(normalized)) {
      if (firstName && currentUser?.semester && branch) {
        return replyInSelectedLanguage(
          `Yes, ${firstName}, I know your profile. You are a semester ${currentUser.semester} ${branch} student at GM University.`,
          `हां ${firstName}, मुझे आपकी प्रोफाइल पता है। आप GM University में सेमेस्टर ${currentUser.semester} के ${branch} छात्र हैं।`
        )
      }

      if (firstName) {
        return replyInSelectedLanguage(
          `Yes, ${firstName}, I know a little about your university profile.`,
          `हां ${firstName}, मुझे आपकी यूनिवर्सिटी प्रोफाइल के बारे में थोड़ी जानकारी है।`
        )
      }

      return replyInSelectedLanguage(
        "Yes, I know a little about your university profile.",
        "हां, मुझे आपकी यूनिवर्सिटी प्रोफाइल के बारे में थोड़ी जानकारी है।"
      )
    }

    if (/\b(family|father|mother|parents|brother|sister|wife|husband)\b|परिवार|पिता|माता|भाई|बहन/.test(normalized)) {
      return replyInSelectedLanguage(
        "I do not have personal information about your family. I only know the academic profile details available in your university account.",
        "मेरे पास आपके परिवार की निजी जानकारी नहीं है। मुझे सिर्फ आपके यूनिवर्सिटी अकाउंट में उपलब्ध अकादमिक प्रोफाइल जानकारी पता है।"
      )
    }

    if (/^(how are you|how are you doing|कैसे हो|आप कैसे हैं)$/.test(normalized)) {
      return replyInSelectedLanguage(
        "I am doing well. I am ready to help you with your questions.",
        "मैं ठीक हूं। आपके सवालों में मदद करने के लिए तैयार हूं।"
      )
    }

    if (/^(who are you|what are you|आप कौन हैं|तुम कौन हो)$/.test(normalized)) {
      return replyInSelectedLanguage(
        "I am GMU VoiceBot, your university assistant for profile, fees, attendance, results, and course support.",
        "मैं GMU VoiceBot हूं, आपका यूनिवर्सिटी असिस्टेंट। मैं प्रोफाइल, फीस, अटेंडेंस, रिजल्ट और कोर्स में मदद कर सकता हूं।"
      )
    }

    if (/^(thank you|thanks|धन्यवाद|शुक्रिया)$/.test(normalized)) {
      return replyInSelectedLanguage(
        "You are welcome. I am happy to help.",
        "आपका स्वागत है। मुझे मदद करके खुशी हुई।"
      )
    }

    if (/^(good morning|good afternoon|good evening|hello|hi|hey|नमस्ते|हेलो)$/.test(normalized)) {
      return firstName
        ? replyInSelectedLanguage(
          `Hello ${firstName}. How can I help you today?`,
          `नमस्ते ${firstName}। मैं आपकी क्या मदद कर सकता हूं?`
        )
        : replyInSelectedLanguage(
          "Hello. How can I help you today?",
          "नमस्ते। मैं आपकी क्या मदद कर सकता हूं?"
        )
    }

    return ""
  }

  const loadProfileCache = async () => {
    if (profileCacheRef.current) {
      return profileCacheRef.current
    }

    const data = await fetchJson("getProfile.php")
    profileCacheRef.current = data
    return data
  }

  const loadPaymentCache = async () => {
    if (paymentCacheRef.current) {
      return paymentCacheRef.current
    }

    const data = await fetchJson("getPaymentDetails.php")
    paymentCacheRef.current = data
    return data
  }

  const loadCoursesCache = async () => {
    if (coursesCacheRef.current) {
      return coursesCacheRef.current
    }

    const data = await fetchJson("getCourses.php")
    coursesCacheRef.current = data
    return data
  }

  const loadResultAvailabilityCache = async () => {
    if (resultAvailabilityCacheRef.current) {
      return resultAvailabilityCacheRef.current
    }

    const data = await fetchJson("getResultAvailability.php")
    resultAvailabilityCacheRef.current = data
    return data
  }

  const formatCurrency = (value) => {
    const amount = Number(value)
    if (!Number.isFinite(amount)) {
      return null
    }

    return new Intl.NumberFormat("en-IN", {
      maximumFractionDigits: 0
    }).format(amount)
  }

  const resetPendingResultQuery = () => {
    pendingResultQueryRef.current = createEmptyResultQuery()
  }

  const resetPendingSpeechRecovery = () => {
    pendingSpeechRecoveryRef.current = createEmptySpeechRecoveryState()
  }

  const rememberSuggestionFollowUp = (actions = []) => {
    const validActions = Array.isArray(actions) ? actions.filter((action) => String(action?.prompt || "").trim()) : []
    if (!validActions.length) {
      pendingSuggestionActionRef.current = null
      return
    }

    const openPaymentAction = validActions.find((action) => /payment|portal/i.test(`${action.label || ""} ${action.prompt || ""}`))
    pendingSuggestionActionRef.current = (openPaymentAction || validActions[0]).prompt.trim()
  }

  const isAffirmativeRecoveryReply = (text) => (
    /\b(yes|yeah|yep|correct|right|haan|han|haudu|yes please|exactly)\b|हाँ|हां|ಹೌದು/u.test(String(text || "").trim().toLowerCase())
  )

  const isNegativeRecoveryReply = (text) => (
    /\b(no|nope|wrong|not that|cancel|nahi|nahi|illa)\b|नहीं|नहि|ಇಲ್ಲ/u.test(String(text || "").trim().toLowerCase())
  )

  const editDistance = (source, target) => {
    const left = String(source || "")
    const right = String(target || "")
    const rows = left.length + 1
    const cols = right.length + 1
    const matrix = Array.from({ length: rows }, () => Array(cols).fill(0))

    for (let row = 0; row < rows; row += 1) {
      matrix[row][0] = row
    }

    for (let col = 0; col < cols; col += 1) {
      matrix[0][col] = col
    }

    for (let row = 1; row < rows; row += 1) {
      for (let col = 1; col < cols; col += 1) {
        const cost = left[row - 1] === right[col - 1] ? 0 : 1
        matrix[row][col] = Math.min(
          matrix[row - 1][col] + 1,
          matrix[row][col - 1] + 1,
          matrix[row - 1][col - 1] + cost
        )
      }
    }

    return matrix[left.length][right.length]
  }

  const formatRecoveredDisplayText = (text) => (
    String(text || "")
      .replace(/\busn\b/gi, "USN")
      .replace(/\bsgpa\b/gi, "SGPA")
      .replace(/\bcgpa\b/gi, "CGPA")
      .replace(/\bdbms\b/gi, "DBMS")
      .replace(/\bsee\b/gi, "SEE")
      .replace(/\bresit\b/gi, "RESIT")
      .replace(/\breregistration\b/gi, "RE-REGISTRATION")
      .replace(/\bodd\b/gi, "ODD")
      .replace(/\beven\b/gi, "EVEN")
      .replace(/\bos\b/gi, "OS")
      .replace(/\bcn\b/gi, "CN")
      .replace(/\bai\b/gi, "AI")
      .trim()
  )

  const getClosestSpeechKeyword = (token) => {
    if (!/^[a-z][a-z0-9-]{2,}$/i.test(token)) {
      return null
    }

    let bestKeyword = null
    let bestDistance = Number.POSITIVE_INFINITY

    SPEECH_RECOVERY_KEYWORDS.forEach((keyword) => {
      if (keyword === token) return
      if (keyword[0] !== token[0]) return

      const distance = editDistance(token, keyword)
      const allowedDistance = token.length <= 4 ? 1 : 2
      if (distance <= allowedDistance && distance < bestDistance) {
        bestKeyword = keyword
        bestDistance = distance
      }
    })

    if (!bestKeyword) {
      return null
    }

    return {
      keyword: bestKeyword,
      distance: bestDistance
    }
  }

  const getSpeechRecoverySuggestion = (text) => {
    if (isHindiMode || isKannadaMode || containsIndicScript(text)) {
      return null
    }

    const raw = String(text || "").trim().toLowerCase()
    if (!raw) {
      return null
    }

    let normalized = ` ${raw} `
    SPEECH_RECOVERY_EXACT_REPLACEMENTS.forEach(({ pattern, replacement }) => {
      normalized = normalized.replace(pattern, ` ${replacement} `)
    })
    normalized = normalized
      .replace(/\s+/g, " ")
      .trim()

    const originalNormalized = normalized
    const tokens = normalized.split(" ").filter(Boolean)
    let changes = 0

    const correctedTokens = tokens.map((token) => {
      if (SPEECH_RECOVERY_STOP_WORDS.has(token) || /^\d+$/.test(token)) {
        return token
      }

      const closest = getClosestSpeechKeyword(token)
      if (!closest) {
        return token
      }

      changes += 1
      return closest.keyword
    })

    normalized = correctedTokens.join(" ").trim()

    if (!normalized || normalized === originalNormalized || changes === 0 || changes > 2) {
      return null
    }

    if (!normalized.split(" ").some((token, index) => token !== tokens[index])) {
      return null
    }

    return {
      correctedText: normalized,
      displayText: formatRecoveredDisplayText(normalized)
    }
  }

  const normalizeVoiceIntent = (text) => {
    const trimmed = String(text || "").trim()
    let normalized = containsIndicScript(trimmed) ? trimmed : trimmed.toLowerCase()

    const replacements = [
      [/\b(shikayat|sikayat|shikayath|complaint|issue|problem|samasya)\b/g, " grievance "],
      [/शिकायत|शिकायात|ग्रिवेंस|ग्रीवेंस|गृवेंस|ग्रीयेवेंस/gu, " grievance "],
      [/फीस|फी|शुल्क|बकाया/u, " fee "],
      [/अटेंडेंस|हाजिरी|उपस्थिति/u, " attendance "],
      [/रिजल्ट|परिणाम|नतीजा|मार्क्स|अंक/u, " result "],
      [/रजिस्ट्रेशन|पंजीकरण/u, " registration "],
      [/प्रोफाइल|प्रोफ़ाइल/u, " profile "],
      [/सर्टिफिकेट|प्रमाणपत्र/u, " certificate "],
      [/सेमेस्टर/u, " semester "],
      [/पेमेंट|भुगतान/u, " payment "],
      [/हॉल\s*टिकट|प्रवेश\s*पत्र/u, " hall ticket "],
      [/\b(ahavalu|ahavaalu|grevans|grievans)\b/g, " grievance "],
      [/ಅಹವಾಲು|ಅಹವಾಳು|ಗ್ರೀವೆನ್ಸ್|ಗ್ರೀವನ್ಸ್|ಗ್ರಿವನ್ಸ್|ಗ್ರೀವನ್ಸ್/gu, " grievance "],
      [/\b(baki fees|baaki fees|due fees|pending fees)\b/g, " fee balance "],
      [/\b(fee balance eshtu|fees balance eshtu|nanna baki fees eshtu|nanna fee balance|fee due eshtu|due amount eshtu)\b/g, " fee balance "],
      [/\b(usn|u s n|yu es en|uesn|yuesen|yusn|upsn|usm|usf|u s m|u s f)\b/g, " usn "],
      [/\b(receipt download|download receipt|receipt barlilla|receipt illa|receipt not generated|receipt not received)\b/g, " download receipt "],
      [/\b(money cut agide|payment deducted|payment cut agide|fee status update agilla|fees status update agilla|fee not updated|payment not updated)\b/g, " payment grievance "],
      [/\bpayment\s*(option|options|aapshan|aapshans|opshan|opshans|apshan|apshans)\b/g, "payment options"],
      [/\bpay\s*ment\s*(option|options)\b/g, "payment options"],
      [/ಪೇಮೆಂಟ್\s*(ಆಪ್ಷನ್|ಆಪ್ಷನ್ಸ್|ಆಪ್ಶನ್|ಆಪ್ಶನ್ಸ್|ಆಪ್ಷನ್ಸ್|ಆಪ್ಶನ್ಸ್)/g, " payment options "],
      [/\bpayment option en ide\b/g, " payment options "],
      [/\b(fees|fee|payment)\s+elli\s+pay\s+madodu\b/g, " how to pay fees "],
      [/\b(payment|fees)\s+hege\s+madodu\b/g, " how to pay fees "],
      [/\bfees pay kaise karna\b/g, " how to pay fees "],
      [/\bpayment page open madu\b/g, " open payment page "],
      [/\b(issue|grievance|complaint)\s+status\s+(hege\s+nododu|nodbeku|kaise\s+dekhna)\b/g, " grievance result "],
      [/\b(grievance|complaint)\s+(hege\s+apply\s+madodu|hege\s+hakodu|kaise\s+apply\s+karna)\b/g, " apply grievance "],
      [/payment ge hogu/g, " open payment page "],
      [/payment portal ge hogu/g, " open payment page "],
      [/ಪೇಮೆಂಟ್\s*(ಪೇಜ್|ಪೋರ್ಟಲ್|ಪುಟ)\s*ಗೆ\s*(ಹೋಗು|ಹೋಗ್ಬು|ತೆರೆ)/g, " open payment page "],
      [/\bback\s*log\b/g, " backlog "],
      [/\bback\s*logs\b/g, " backlog "],
      [/\bbacklogs\b/g, " backlog "],
      [/ಬ್ಯಾಕ್\s*(ಲಾಗ್|ಲೋಗ್|ಲಾಕ್)(್ಸ್|ಸ್)?/g, " backlog "],
      [/ಬ್ಯಾಕ್\s*(ಲಾಗ್|ಲೋಗ್|ಲಾಕ್)(್ಸ್|ಸ್)?/g, " backlog "],
      [/ಬ್ಯಾಕ್?(ಲಾಗ್|ಲೋಗ್|ಲಾಕ್)(್ಸ್|ಸ್)?/g, " backlog "],
      [/ಫೇಲ್\s*subject/g, " failed subject "],
      [/\b(single step|singal step|single sem|sem step)\b/g, " semester "],
      [/\b(s\s*e\s*e|s\s*ee)\b/g, " see "],
      [/\bc\b/g, " see "],
      [/subject\s*code/g, " course code "],
      [/course\s*code/g, " course code "],
      [/ಕೋರ್ಸ್\s*ಕೋಡ್/g, " course code "],
      [/ಸಬ್ಜೆಕ್ಟ್\s*ಕೋಡ್/g, " subject code "],
      [/ವಿಷಯದ\s*ಕೋಡ್/g, " subject code "]
    ]

    replacements.forEach(([pattern, replacement]) => {
      normalized = normalized.replace(pattern, replacement)
    })

    normalized = normalized.replace(/\s+/g, " ").trim()
    return normalized
  }

  const getResultSupportReply = async (text) => {
    const normalized = (text || "").trim().toLowerCase()
    const hasResultWord = /\b(result|results|marks|marksheet|grade sheet|gradesheet|sgpa)\b|रिजल्ट|नतीजा|मार्क्स|ग्रेड|ಫಲಿತಾಂಶ|ರಿಸಲ್ಟ್|ಮಾರ್ಕ್ಸ್|ಗ್ರೇಡ್/u.test(normalized)

    if (!hasResultWord) {
      return null
    }

    const isProcessQuery = /\b(how to check|how can i check|how to see|where to see|where can i see|how to get|steps|process|check result|see result)\b|कैसे|कहाँ|कहा|स्टेप्स|ಹೇಗೆ|ಏಲ್ಲಿ|ಸ್ಟೆಪ್ಸ್/u.test(normalized)

    if (isProcessQuery) {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To check your result, open Student Result under the Result button. Enter USN, select Semester, select Exam like SEE, RESIT, or RE-REGISTRATION, choose Year, choose Season as ODD or EVEN, then click Submit.",
          "Result check karne ke liye Result button ke andar Student Result kholiye. USN dijiye, Semester select kijiye, Exam mein SEE, RESIT, ya RE-REGISTRATION chuniye, Year select kijiye, Season mein ODD ya EVEN chuniye, phir Submit kijiye.",
          "Result nodalu Result button alli Student Result tereyiri. USN haki, Semester select madi, Exam nalli SEE, RESIT, athava RE-REGISTRATION ayke madi, Year select madi, Season nalli ODD athava EVEN ayke madi, nantara Submit madi."
        )
      }
    }

    const isInformationQuery = /\b(what is|show|tell|check|view|display|my|give)\b|क्या|दिखा|बता|मेरा|ನನ್ನ|ತೋರಿಸು|ಹೇಳಿ/u.test(normalized)

    if (!isInformationQuery) {
      return null
    }

    const profile = await loadProfileCache()
    const knownUsn = String(profile?.usn || "").trim()
    const enteredUsnMatch = normalized.match(/\b[a-z]{2,}[0-9]{2,}[a-z0-9]{3,}\b/i)
    const semesterMatch = normalized.match(/\b(?:semester|sem)\s*(\d+)\b/)
    const yearMatch = normalized.match(/\b(20\d{2}\s*-\s*\d{2})\b/)
    const examMatch = normalized.match(/\b(see|resit|re-registration|reregistration)\b/)
    const seasonMatch = normalized.match(/\b(odd|even)\b/)

    const semesterWordMap = {
      first: 1,
      second: 2,
      third: 3,
      fourth: 4,
      fifth: 5,
      sixth: 6,
      seventh: 7,
      eighth: 8
    }

    let semesterValue = semesterMatch ? semesterMatch[1] : ""
    if (!semesterValue) {
      Object.entries(semesterWordMap).some(([word, value]) => {
        if (normalized.includes(`${word} semester`) || normalized.includes(`${word} sem`)) {
          semesterValue = String(value)
          return true
        }
        return false
      })
    }

    const missingFields = []
    if (!knownUsn && !enteredUsnMatch) missingFields.push("USN")
    if (!semesterValue) missingFields.push("Semester")
    if (!examMatch) missingFields.push("Exam")
    if (!yearMatch) missingFields.push("Year")
    if (!seasonMatch) missingFields.push("Season")

    if (missingFields.length > 0) {
      const missingList = missingFields.join(", ")
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          semesterValue
            ? `I can help you check your ${getOrdinalLabel(semesterValue) || semesterValue} semester result. Please share these remaining details: ${missingList}.`
            : `I can help you check your result. Since results are available semester-wise, please tell me your semester first, then share these details: ${missingList}.`,
          semesterValue
            ? `Main aapka semester ${semesterValue} ka result check karne mein help kar sakta hoon. Kripya ye baaki details dijiye: ${missingList}.`
            : `Main aapka result check karne mein help kar sakta hoon. Kyunki result semester-wise available hota hai, kripya pehle apna Semester batayiye, phir ye details dijiye: ${missingList}.`,
          semesterValue
            ? `Nanu nimma ${semesterValue}ne semester result check madalu sahaya madabahudu. Dayavittu ulida details kodi: ${missingList}.`
            : `Nimma result check madalu nanu sahaya madabahudu. Result semester-wise labhyaviruvudariinda, dayavittu modalu nimma Semester heli, nantara ee details kodi: ${missingList}.`
        )
      }
    }

    return null
  }

  const getGuidedResultSupportReply = async (text) => {
    const normalized = (text || "").trim().toLowerCase()
    const pendingQuery = pendingResultQueryRef.current
    const hasResultWord = /\b(result|results|marks|marksheet|grade sheet|gradesheet|sgpa)\b|result|marks/u.test(normalized)
    const hasContinuationWord = /\b(yes|yeah|haan|ha|haudu|continue|go ahead)\b|हाँ|ಹೌದು/u.test(normalized)
    const hasStructuredSlotAnswer = /\b[a-z]{2,}[0-9]{2,}[a-z0-9]{3,}\b/i.test(normalized)
      || /\b(?:semester|sem)\s*\d+\b/.test(normalized)
      || /\b(first|second|third|fourth|fifth|sixth|seventh|eighth)\s+(semester|sem)\b/.test(normalized)
      || /\b(first|second|third|fourth|fifth|sixth|seventh|eighth)\b/.test(normalized)
      || /\b[1-8](?:st|nd|rd|th)?\b/.test(normalized)
      || /\b(see|resit|re-registration|reregistration|odd|even)\b/.test(normalized)
      || /\b20\d{2}\s*(?:-\s*|\s+)(?:20)?\d{2}\b/.test(normalized)
    const explicitlyCancelsResultFlow = /\b(cancel|stop|leave|exit|skip|never mind|dont need|don't need|not result|no result|something else|another question)\b/u.test(normalized)
    const hasStrongNonResultIntent = /\b(timetable|time table|schedule|class schedule|profile|my profile|attendance|fee|fees|payment|certificate|registration|dashboard|home|course|subject|hall ticket|usn|cgpa|backlog|grievance|receipt)\b/u.test(normalized)

    if (pendingQuery.active && (explicitlyCancelsResultFlow || (hasStrongNonResultIntent && !hasResultWord && !hasStructuredSlotAnswer))) {
      resetPendingResultQuery()
      return null
    }

    const buildMissingFieldPrompt = (nextMissingField, nextQuery, invalidAttempt = false, fieldOptions = {}) => {
      const examOptions = fieldOptions.examOptions?.length ? fieldOptions.examOptions : RESULT_EXAM_OPTIONS
      const yearOptions = fieldOptions.yearOptions?.length ? fieldOptions.yearOptions : ["2024-25"]
      const seasonOptions = fieldOptions.seasonOptions?.length ? fieldOptions.seasonOptions : RESULT_SEASON_OPTIONS

      return replyInSelectedLanguage(
      nextMissingField === "Semester"
        ? (invalidAttempt
          ? "I am still helping you check your result. Please tell me only the semester first. You can say fourth semester."
          : "I can help you check your result step by step. First, please tell me which semester you want to check. You can simply say fourth semester.")
        : nextMissingField === "Exam"
          ? (invalidAttempt
            ? `I am still checking your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester result. Please tell me only the exam type, such as ${examOptions.join(", ")}.`
            : `I can help you check your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester result step by step. Please tell me the exam type, such as ${examOptions.join(", ")}.`)
          : nextMissingField === "Year"
            ? (invalidAttempt
              ? `I am still checking your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester ${nextQuery.exam} result. Please tell me only the academic year, for example ${yearOptions.join(" or ")}.`
              : `Please tell me the academic year for your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester ${nextQuery.exam} result, for example ${yearOptions.join(" or ")}.`)
            : nextMissingField === "Season"
              ? (invalidAttempt
                ? `I am still checking your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester ${nextQuery.exam} ${nextQuery.year} result. Please tell me only the season. You can say ${seasonOptions.join(" or ")}.`
                : `Please tell me the season for your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester ${nextQuery.exam} ${nextQuery.year} result. You can say ${seasonOptions.join(" or ")}.`)
              : (invalidAttempt
                ? `I am still checking your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester result. Please share only your USN first.`
                : `Please share your USN so I can check your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester result.`),
      nextMissingField === "Semester"
        ? (invalidAttempt
          ? "Main abhi bhi aapka result check karne mein help kar raha hoon. Kripya pehle sirf semester batayiye. Aap fourth semester keh sakte hain."
          : "Main aapka result step by step check karne mein help kar sakta hoon. Sabse pehle batayiye ki aap kaunsa semester check karna chahte hain. Aap simply fourth semester bhi keh sakte hain.")
        : nextMissingField === "Exam"
          ? (invalidAttempt
            ? `Main abhi bhi semester ${nextQuery.semester} ka result check kar raha hoon. Kripya sirf exam type batayiye, jaise ${examOptions.join(", ")}.`
            : `Main aapka semester ${nextQuery.semester} ka result step by step check karne mein help kar sakta hoon. Kripya exam type batayiye, jaise ${examOptions.join(", ")}.`)
          : nextMissingField === "Year"
            ? (invalidAttempt
              ? `Main abhi bhi semester ${nextQuery.semester} ke ${nextQuery.exam} result ke liye wait kar raha hoon. Kripya sirf academic year batayiye, jaise ${yearOptions.join(" ya ")}.`
              : `Kripya semester ${nextQuery.semester} ke ${nextQuery.exam} result ka academic year batayiye, jaise ${yearOptions.join(" ya ")}.`)
            : nextMissingField === "Season"
              ? (invalidAttempt
                ? `Main abhi bhi semester ${nextQuery.semester} ke ${nextQuery.exam} ${nextQuery.year} result ke liye wait kar raha hoon. Kripya sirf season batayiye. Aap ${seasonOptions.join(" ya ")} keh sakte hain.`
                : `Kripya semester ${nextQuery.semester} ke ${nextQuery.exam} ${nextQuery.year} result ka season batayiye. Aap ${seasonOptions.join(" ya ")} keh sakte hain.`)
              : (invalidAttempt
                ? `Main abhi bhi semester ${nextQuery.semester} ka result check kar raha hoon. Kripya sirf apna USN batayiye.`
                : `Kripya apna USN batayiye, phir main semester ${nextQuery.semester} ka result check kar sakta hoon.`),
      nextMissingField === "Semester"
        ? (invalidAttempt
          ? "Nanu innu nimma result check maduttiddene. Dayavittu modalu semester matra heli. Neevu fourth semester endu helabahudu."
          : "Nimma result step by step check madalu nanu sahaya madabahudu. Modalu yav semester result bekendu heli. Neevu fourth semester anta simple aagi helabahudu.")
        : nextMissingField === "Exam"
          ? (invalidAttempt
            ? `Nanu innu nimma ${nextQuery.semester}ne semester result check maduttiddene. Dayavittu exam type matra heli, udaharanege ${examOptions.join(", ")}.`
            : `Nanu nimma ${nextQuery.semester}ne semester result step by step check madalu sahaya madabahudu. Dayavittu exam type heli, udaharanege ${examOptions.join(", ")}.`)
          : nextMissingField === "Year"
            ? (invalidAttempt
              ? `Nanu innu nimma ${nextQuery.semester}ne semester ${nextQuery.exam} result ge kayuttiddene. Dayavittu academic year matra heli, udaharanege ${yearOptions.join(" athava ")}.`
              : `Dayavittu nimma ${nextQuery.semester}ne semester ${nextQuery.exam} result ge academic year heli, udaharanege ${yearOptions.join(" athava ")}.`)
            : nextMissingField === "Season"
              ? (invalidAttempt
                ? `Nanu innu nimma ${nextQuery.semester}ne semester ${nextQuery.exam} ${nextQuery.year} result ge kayuttiddene. Dayavittu season matra heli. Neevu ${seasonOptions.join(" athava ")} endu helabahudu.`
                : `Dayavittu nimma ${nextQuery.semester}ne semester ${nextQuery.exam} ${nextQuery.year} result ge season heli. Neevu ${seasonOptions.join(" athava ")} endu helabahudu.`)
              : (invalidAttempt
                ? `Nanu innu nimma ${nextQuery.semester}ne semester result check maduttiddene. Dayavittu nimma USN matra heli.`
                : `Dayavittu nimma USN heli, nantara nanu ${nextQuery.semester}ne semester result check madabahudu.`)
    )
    }

    if (!hasResultWord && !pendingQuery.active) {
      return null
    }

    if (pendingQuery.active && !hasResultWord && !hasContinuationWord && !hasStructuredSlotAnswer) {
      const activeMissingFields = []
      if (!pendingQuery.usn) activeMissingFields.push("USN")
      if (!pendingQuery.semester) activeMissingFields.push("Semester")
      if (!pendingQuery.exam) activeMissingFields.push("Exam")
      if (!pendingQuery.year) activeMissingFields.push("Year")
      if (!pendingQuery.season) activeMissingFields.push("Season")
      const nextMissingField = activeMissingFields[0] || "Semester"
      return {
        type: "reply",
        reply: buildMissingFieldPrompt(nextMissingField, pendingQuery, true)
      }
    }

    const isProcessQuery = /\b(how to check|how can i check|how to see|where to see|where can i see|how to get|steps|process|check result|see result)\b/u.test(normalized)
    if (isProcessQuery) {
      resetPendingResultQuery()
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To check your result, open Student Result under the Result button. Enter USN, select Semester, select Exam like SEE, RESIT, or RE-REGISTRATION, choose Year, choose Season as ODD or EVEN, then click Submit.",
          "Result check karne ke liye Result button ke andar Student Result kholiye. USN dijiye, Semester select kijiye, Exam mein SEE, RESIT, ya RE-REGISTRATION chuniye, Year select kijiye, Season mein ODD ya EVEN chuniye, phir Submit kijiye.",
          "Result nodalu Result button alli Student Result tereyiri. USN haki, Semester select madi, Exam nalli SEE, RESIT, athava RE-REGISTRATION ayke madi, Year select madi, Season nalli ODD athava EVEN ayke madi, nantara Submit madi."
        )
      }
    }

    const isInformationQuery = /\b(what is|show|tell|check|view|display|my|give|latest)\b/u.test(normalized)
    if (!isInformationQuery && !pendingQuery.active) {
      return null
    }

    const profile = await loadProfileCache()
    const knownUsn = String(profile?.usn || "").trim()
    const enteredUsnMatch = normalized.match(/\b[a-z]{2,}[0-9]{2,}[a-z0-9]{3,}\b/i)
    const semesterMatch = normalized.match(/\b(?:semester|sem)\s*(\d+)\b/)
    const normalizedAcademicYear = normalizeAcademicYearInput(normalized)
    const examMatch = normalized.match(/\b(see|resit|re-registration|reregistration)\b/)
    const seasonMatch = normalized.match(/\b(odd|even)\b/)
    const explicitlyMentionsSemesterField = /\b(?:semester|sem)\b/.test(normalized)
    const semesterWordMap = {
      first: 1,
      second: 2,
      third: 3,
      fourth: 4,
      fifth: 5,
      sixth: 6,
      seventh: 7,
      eighth: 8
    }

    let semesterValue = semesterMatch ? semesterMatch[1] : ""
    if (!semesterValue && !pendingQuery.semester) {
      const standaloneSemesterMatch = normalized.match(/\b([1-8])(?:st|nd|rd|th)?\b/)
      if (standaloneSemesterMatch) {
        semesterValue = standaloneSemesterMatch[1]
      }
    }
    if (!semesterValue && (!pendingQuery.semester || explicitlyMentionsSemesterField)) {
      Object.entries(semesterWordMap).some(([word, value]) => {
        if (
          normalized.includes(`${word} semester`) ||
          normalized.includes(`${word} sem`) ||
          (!pendingQuery.semester && (normalized === word || normalized.startsWith(`${word} `)))
        ) {
          semesterValue = String(value)
          return true
        }
        return false
      })
    }

    const normalizedExam = examMatch
      ? (examMatch[1] === "reregistration" ? "RE-REGISTRATION" : examMatch[1].toUpperCase())
      : ""
    const normalizedYear = normalizedAcademicYear || ""
    const normalizedSeason = seasonMatch ? seasonMatch[1].toUpperCase() : ""

    const nextQuery = {
      active: true,
      usn: (enteredUsnMatch?.[0] || pendingQuery.usn || knownUsn || "").toUpperCase(),
      semester: semesterValue || pendingQuery.semester || "",
      exam: normalizedExam || pendingQuery.exam || "",
      year: normalizedYear || pendingQuery.year || "",
      season: normalizedSeason || pendingQuery.season || ""
    }

    const availabilityData = await loadResultAvailabilityCache().catch(() => null)
    const availableSelections = Array.isArray(availabilityData?.selections) ? availabilityData.selections : []
    const semesterSelections = nextQuery.semester
      ? availableSelections.filter((selection) => String(selection.semester) === String(nextQuery.semester))
      : []
    const availableExamOptions = Array.from(new Set(semesterSelections.map((selection) => selection.exam).filter(Boolean)))

    if (nextQuery.semester && nextQuery.exam && availableExamOptions.length && !availableExamOptions.includes(nextQuery.exam)) {
      nextQuery.exam = ""
      nextQuery.year = ""
      nextQuery.season = ""
    }

    const examSelections = nextQuery.exam
      ? semesterSelections.filter((selection) => selection.exam === nextQuery.exam)
      : []
    const availableYearOptions = Array.from(new Set(examSelections.map((selection) => selection.year).filter(Boolean)))

    if (nextQuery.semester && nextQuery.exam && nextQuery.year && availableYearOptions.length && !availableYearOptions.includes(nextQuery.year)) {
      nextQuery.year = ""
      nextQuery.season = ""
    }

    const yearSelections = nextQuery.year
      ? examSelections.filter((selection) => selection.year === nextQuery.year)
      : []
    const availableSeasonOptions = Array.from(new Set(yearSelections.map((selection) => selection.season).filter(Boolean)))

    if (nextQuery.semester && nextQuery.exam && nextQuery.year && nextQuery.season && availableSeasonOptions.length && !availableSeasonOptions.includes(nextQuery.season)) {
      nextQuery.season = ""
    }

    pendingResultQueryRef.current = nextQuery

    const missingFields = []
    if (!nextQuery.usn) missingFields.push("USN")
    if (!nextQuery.semester) missingFields.push("Semester")
    if (!nextQuery.exam) missingFields.push("Exam")
    if (!nextQuery.year) missingFields.push("Year")
    if (!nextQuery.season) missingFields.push("Season")

    if (missingFields.length > 0) {
      const nextMissingField = missingFields[0]
      return {
        type: "reply",
        reply: buildMissingFieldPrompt(nextMissingField, nextQuery, false, {
          examOptions: availableExamOptions,
          yearOptions: availableYearOptions,
          seasonOptions: availableSeasonOptions
        })
      }
    }

    resetPendingResultQuery()
    return {
      type: "navigate_result",
      reply: replyInSelectedLanguage(
        `I have the details for your ${getOrdinalLabel(nextQuery.semester) || nextQuery.semester} semester result. Opening the result page now.`,
        `Mujhe aapke semester ${nextQuery.semester} ke result ki details mil gayi hain. Main ab result page khol raha hoon.`,
        `Nimma ${nextQuery.semester}ne semester result ge bekaada details sigive. Nanu iga result page tereyuttiddene.`
      ),
      selection: {
        usn: nextQuery.usn,
        semester: nextQuery.semester,
        exam: nextQuery.exam,
        year: nextQuery.year,
        season: nextQuery.season
      }
    }
  }

  const getAttendanceAnalyticsSupportReply = async (text) => {
    const normalized = (text || "").trim().toLowerCase()
    const asksAttendance = /\b(attendance|attendence|atendance)\b|ಹಾಜರಿ|ಹಾಜರಾತಿ|ಅಟೆಂಡೆನ್ಸ್|अटेंडेंस|उपस्थिति/u.test(normalized)
    const asksGraphStyle = /\b(graph|chart|bar chart|statistics|stats|analytics|analysis|visual|graphical|subject wise|subject-wise|all subjects|current sem|current semester)\b|ಗ್ರಾಫ್|ಚಾರ್ಟ್|ಅನಾಲಿಟಿಕ್ಸ್|ಸ್ಟಾಟಿಸ್ಟಿಕ್ಸ್|subject wise|ग्राफ|चार्ट|स्टैटिस्टिक्स|एनालिटिक्स/u.test(normalized)
    const asksToShow = /\b(show|display|open|view|see|take me|navigate|give me)\b|ತೋರಿಸಿ|ತೆರೆಯಿರಿ|ನೋಡಿ|दिखाइए|खोलिए|देखिए/u.test(normalized)

    if (!asksAttendance || !(asksGraphStyle || asksToShow && /\b(statistics|stats|graph|chart|subject wise|subject-wise)\b/u.test(normalized))) {
      return null
    }

    return {
      type: "navigate",
      page: "attendance-analytics",
      reply: replyInSelectedLanguage(
        "Opening your current semester subject-wise attendance graph now.",
        "मैं अभी आपके current semester की subject-wise attendance graph खोल रहा हूँ।",
        "Nimma current semester subject-wise attendance graph iga tereyuttiddene."
      )
    }
  }

  const getPaymentSupportReply = async (text) => {
    const normalized = (text || "").trim().toLowerCase()
    const detectStructuredPaymentIntent = () => {
      const grievanceWordPattern = /\b(grievance|graviance|grevience|gradient|gradients|shikayat|sikayat|complaint|ahavalu|ahavaalu|grevans|grievans)\b|शिकायत|शिकायात|ग्रिवेंस|ग्रीवेंस|गृवेंस|ग्रीयेवेंस|ಅಹವಾಲು|ಅಹವಾಳು|ಗ್ರೀವೆನ್ಸ್|ಗ್ರೀವನ್ಸ್|ಗ್ರಿವನ್ಸ್|ಗ್ರೀವನ್ಸ್/u
      const grievanceApplyPattern = /\b(apply|raise|submit|file|register|complain|kahan|kahaan|kaise|elli|yelli|ellii|hege|henge)\b|कहाँ|कहां|कैसे|दर्ज|जमा|ಮಾಡಿ|ಸಲ್ಲಿಸಿ|ದೂರು|ಎಲ್ಲಿ|ಹೇಗೆ/u
      const grievanceResultPattern = /\b(check|track|result|status|history|see|view|find|dekho|dekhe|dekhna|nodi|nodu|sthiti|parinam|phalitansh)\b|देख|देखें|स्थिति|परिणाम|ನೋಡಿ|ನೋಡು|ಸ್ಥಿತಿ|ಫಲಿತಾಂಶ/u

      if (/\b(how to check|where can i see|where to see|how can i see|check fee balance|see fee balance|view fee balance|balance check)\b/.test(normalized)
        && /\b(fee|fees|balance|due|pending)\b/.test(normalized)) {
        return "FEES_BALANCE_STEPS"
      }

      if (/\b(what is my fee balance|what is fee balance|what is the fee balance|what is due amount|how much fee pending|how much fee due|how much fee|due amount|amount due|fee balance|fees balance|pending fees|pending fee|due fees|fee due)\b/.test(normalized)) {
        return "FEES_BALANCE_VALUE"
      }

      if (/\b(how to pay fees|how do i pay fees|how can i pay my fee|how can i pay my fees|where to pay fees|where can i pay my fee|where can i pay my fees|where i can pay my fee|where i can pay my fees|pay fees|pay my fee|pay my fees|fee payment|pay college fee|pay hostel fee)\b/.test(normalized)) {
        return "PAY_FEES"
      }

      if (/\b(payment options|what payment options|what are the payment options|payment methods|which fees can i pay|what fees can i pay|available fee options|available payment options)\b/.test(normalized)) {
        return "PAYMENT_OPTIONS"
      }

      if (grievanceWordPattern.test(normalized) && grievanceResultPattern.test(normalized)) {
        return "GRIEVANCE_RESULT"
      }

      if ((grievanceWordPattern.test(normalized) && grievanceApplyPattern.test(normalized))
        || /\braise complaint\b/.test(normalized)) {
        return "APPLY_GRIEVANCE"
      }

      return null
    }

    const structuredIntent = detectStructuredPaymentIntent()
    let resolvedStructuredIntent = structuredIntent

    if (!resolvedStructuredIntent) {
      if (/\b(download receipt|receipt download|how to download receipt|receipt hege download madodu|receipt kaise download karna)\b/.test(normalized)) {
        resolvedStructuredIntent = "DOWNLOAD_RECEIPT"
      } else if (/\b(fee balance eshtu|fees balance eshtu|nanna baki fees eshtu|nanna fee balance|due fees eshtu|fee due eshtu|fee balance ide)\b/.test(normalized)) {
        resolvedStructuredIntent = "FEES_BALANCE_VALUE"
      } else if (/\b(fees elli pay madodu|payment hege madodu|fees pay kaise karna|payment kaise karna|how to do payment)\b/.test(normalized)) {
        resolvedStructuredIntent = "PAY_FEES"
      } else if (/\b(payment option en ide|payment options en ide)\b/.test(normalized)) {
        resolvedStructuredIntent = "PAYMENT_OPTIONS"
      } else if (/\b(issue status hege nododu|issue status nodbeku|complaint status nodbeku|grievance status nodbeku|grievance status hege nododu|issue status kaise dekhna)\b/.test(normalized)) {
        resolvedStructuredIntent = "GRIEVANCE_RESULT"
      } else if (/\b(grievance hege apply madodu|grievance hege hakodu|complaint hege hakodu|grievance kaise apply karna|complaint kaise apply karna)\b/.test(normalized)) {
        resolvedStructuredIntent = "APPLY_GRIEVANCE"
      }
    }

    if (resolvedStructuredIntent === "FEES_BALANCE_STEPS") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To check your fee balance, open the Registration page. In the Payment Details section, you can see total fee, paid amount, and remaining balance.",
          "फीस बैलेंस देखने के लिए रजिस्ट्रेशन पेज खोलिए। पेमेंट डिटेल्स सेक्शन में आप कुल फीस, जमा की गई राशि और बाकी बैलेंस देख सकते हैं।",
          "Fee balance nodalu Registration page tereyiri. Payment Details section nalli total fee, paid amount, mattu remaining balance nodabahudu."
        )
      }
    }

    if (resolvedStructuredIntent === "FEES_BALANCE_VALUE") {
      const payments = await loadPaymentCache()
      const hasBalanceData = Array.isArray(payments) && payments.length > 0
      const totalBalance = hasBalanceData
        ? payments.reduce((sum, item) => sum + Number(item.balance || 0), 0)
        : null
      const formattedBalance = totalBalance == null ? null : formatCurrency(totalBalance)

      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          formattedBalance != null
            ? `Your current pending fee balance is rupees ${formattedBalance}.`
            : "To check your fee balance, open the Registration page and view the Payment Details section.",
          formattedBalance != null
            ? `आपका वर्तमान बकाया फीस बैलेंस ${formattedBalance} रुपये है।`
            : "फीस बैलेंस देखने के लिए रजिस्ट्रेशन पेज खोलिए और पेमेंट डिटेल्स सेक्शन देखिए।",
          formattedBalance != null
            ? `Nimma eegina pending fee balance ${formattedBalance} rupayi ide.`
            : "Fee balance nodalu Registration page tereyiri mattu Payment Details section nodi."
        )
      }
    }

    if (resolvedStructuredIntent === "PAY_FEES") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To pay your fees, go to the Registration page and scroll down. Click on the Payment button. In GM Smart Pay, select the required fee option and proceed.",
          "फीस भरने के लिए रजिस्ट्रेशन पेज पर जाइए और नीचे स्क्रॉल कीजिए। पेमेंट बटन दबाइए। GM Smart Pay में जरूरी फीस विकल्प चुनकर आगे बढ़िए।",
          "Fees pavatisalu Registration page ge hogi kelage scroll madi. Payment button ottiri. GM Smart Pay nalli bekaada fee option ayke madi munduvariyiri."
        )
      }
    }

    if (resolvedStructuredIntent === "PAYMENT_OPTIONS") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "After clicking the Payment button, you will see options like College or Tuition Fee, Hostel Fee, Skill or Late Registration Fee, Download Receipt, Payment Grievance, and Grievance Result.",
          "पेमेंट बटन दबाने के बाद आपको College या Tuition Fee, Hostel Fee, Skill या Late Registration Fee, Download Receipt, Payment Grievance और Grievance Result जैसे विकल्प दिखेंगे।",
          "Payment button ottida mele College athava Tuition Fee, Hostel Fee, Skill athava Late Registration Fee, Download Receipt, Payment Grievance, mattu Grievance Result tara options kanisuttave."
        )
      }
    }

    if (resolvedStructuredIntent === "DOWNLOAD_RECEIPT") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To download your receipt, open the Registration page, click the Payment button, then choose Download Receipt. Enter the needed details and download the receipt.",
          "Receipt download karne ke liye Registration page kholiye, Payment button dabaiye, phir Download Receipt chuniye. Zaroori details dijiye aur receipt download kijiye.",
          "Receipt download madalu Registration page tereyiri, Payment button ottiri, nantara Download Receipt ayke madi. Bekada details kodi mattu receipt download madi."
        )
      }
    }

    if (resolvedStructuredIntent === "APPLY_GRIEVANCE") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To apply for a payment grievance, go to the Registration page, click on the Payment button, then select Payment Grievance. Enter your details and submit.",
          "पेमेंट शिकायत दर्ज करने के लिए रजिस्ट्रेशन पेज पर जाइए, पेमेंट बटन दबाइए, फिर Payment Grievance चुनिए। अपनी जानकारी भरकर सबमिट कीजिए।",
          "Payment grievance haakalu Registration page ge hogi, Payment button ottiri, nantara Payment Grievance ayke madi. Nimma details tumbi submit madi."
        )
      }
    }

    if (resolvedStructuredIntent === "GRIEVANCE_RESULT") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To check grievance result, go to the Registration page, click on the Payment button, then select Grievance Result. Enter your USN and submit.",
          "शिकायत का परिणाम देखने के लिए रजिस्ट्रेशन पेज पर जाइए, पेमेंट बटन दबाइए, फिर Grievance Result चुनिए। अपना USN भरकर सबमिट कीजिए।",
          "Grievance result nodalu Registration page ge hogi, Payment button ottiri, nantara Grievance Result ayke madi. Nimma USN haki submit madi."
        )
      }
    }
    const paymentIntent = /\b(payment|pay fees|pay my fees|fee payment|payment options|how can i pay|how to pay|receipt|grievance|graviance|grevience|gradients|shikayat|sikayat|complaint|ahavalu|ahavaalu)\b|शिकायत|ग्रिवेंस|ग्रीवेंस|ಅಹವಾಲು|ಗ್ರೀವೆನ್ಸ್/u.test(normalized)
      || /ಪಾವತಿ|ಫೀಸ್ ಪಾವತಿ|ಪೇಮೆಂಟ್|ರಿಸೀಪ್ಟ್|ಗ್ರೀವನ್ಸ್|payment options/u.test(normalized)
      || /पेमेंट|फीस पेमेंट|रसीद|ग्रिवेंस/u.test(normalized)

    if (!paymentIntent) {
      return null
    }

    if (/\b(open|go|navigate|take me|show me|visit|hogu|tere|open madi|open madu)\b/.test(normalized)
      || normalized.includes("payment page")
      || normalized.includes("payment portal")
      || normalized.includes("payment ge hogu")
      || normalized.includes("payment page open madu")
      || normalized.includes("ಪೇಮೆಂಟ್ ಪೇಜ್")
      || normalized.includes("ಪಾವತಿ ಪುಟ")
      || normalized.includes("पेमेंट पेज")) {
      return {
        type: "navigate",
        page: "payment",
        reply: replyInSelectedLanguage(
          "Opening your payment portal.",
          "आपका payment portal खोल रहा हूं।",
          "ನಿಮ್ಮ payment portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
      }
    }

    const payments = await loadPaymentCache()
    const totalBalance = Array.isArray(payments)
      ? payments.reduce((sum, item) => sum + Number(item.balance || 0), 0)
      : 0
    const formattedBalance = formatCurrency(totalBalance) || "0"

    const grievanceWordPattern = /\b(grievance|graviance|grevience|gradient|gradients|shikayat|sikayat|complaint|ahavalu|ahavaalu|grevans|grievans)\b|शिकायत|शिकायात|ग्रिवेंस|ग्रीवेंस|गृवेंस|ग्रीयेवेंस|ಅಹವಾಲು|ಅಹವಾಳು|ಗ್ರೀವೆನ್ಸ್|ಗ್ರೀವನ್ಸ್|ಗ್ರಿವನ್ಸ್|ಗ್ರೀವನ್ಸ್/u
    const grievanceApplyPattern = /\b(apply|raise|submit|file|register|complain|kahan|kahaan|kaise|elli|yelli|ellii|hege|henge)\b|कहाँ|कहां|कैसे|दर्ज|जमा|ಮಾಡಿ|ಸಲ್ಲಿಸಿ|ದೂರು|ಎಲ್ಲಿ|ಹೇಗೆ/u
    const grievanceResultPattern = /\b(check|track|result|status|history|see|view|find|dekho|dekhe|dekhna|nodi|nodu|sthiti|parinam|phalitansh)\b|देख|देखें|स्थिति|परिणाम|ನೋಡಿ|ನೋಡು|ಸ್ಥಿತಿ|ಫಲಿತಾಂಶ/u
    const isGrievanceResultQuery = grievanceWordPattern.test(normalized) && grievanceResultPattern.test(normalized)
    const isGrievanceHelpQuery = (grievanceWordPattern.test(normalized) && grievanceApplyPattern.test(normalized))
      || /\braise complaint\b/.test(normalized)
      || /\bfee status not updated|fees status not updated|payment deducted|receipt not generated|wrong fee mapping|fee not updated|money cut agide|payment problem ide|payment problem hai|fee status update agilla|receipt barlilla\b/.test(normalized)

    if (isGrievanceResultQuery) {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To check your grievance result, open the payment portal and select Grievance Result. Then search with your USN, phone number, or grievance number to view the latest status and remarks.",
          "शिकायत का परिणाम देखने के लिए पेमेंट पोर्टल खोलिए और Grievance Result चुनिए। फिर अपना USN, फोन नंबर या शिकायत नंबर भरकर नवीनतम स्थिति और टिप्पणी देखिए।",
          "Grievance result nodalu payment portal tereyiri mattu Grievance Result ayke madi. Nantara nimma USN, phone number, athava grievance number haki latest status mattu remarks nodi."
        )
      }
    }

    if (isGrievanceHelpQuery) {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "If your fee status is not updated, open the payment portal and choose Payment Grievance. Enter your USN, phone number, payment amount, transaction date, and issue details, then upload payment proof if you have it. After submission, use Grievance Result to track the update.",
          "अगर आपकी फीस स्थिति अपडेट नहीं हुई है, तो पेमेंट पोर्टल में जाकर Payment Grievance खोलिए। अपना USN, फोन नंबर, भुगतान राशि, लेनदेन तिथि और समस्या का विवरण भरिए, और यदि प्रमाण है तो अपलोड कीजिए। सबमिट करने के बाद Grievance Result से अपडेट देखिए।",
          "Nimma fee status update agilladiddare payment portal nalli Payment Grievance tereyiri. Nimma USN, phone number, payment amount, transaction date, mattu issue details kodi, proof iddare upload madi. Submit madida mele Grievance Result nalli update nodabahudu."
        )
      }
    }

    return {
      type: "reply",
      reply: replyInSelectedLanguage(
        `You can pay your fees from the payment portal. Available options are College or Tuition Fee, Hostel Fee, Skill or Late Registration or Other Fee, Download Receipt, Payment Grievance, and Grievance Result. Your current pending balance is rupees ${formattedBalance}.`,
        `आप पेमेंट पोर्टल से अपनी फीस भर सकते हैं। उपलब्ध विकल्प हैं College या Tuition Fee, Hostel Fee, Skill या Late Registration या Other Fee, Download Receipt, Payment Grievance और Grievance Result। आपका वर्तमान बकाया बैलेंस ${formattedBalance} रुपये है।`,
        `ನೀವು payment portal ಮೂಲಕ ನಿಮ್ಮ fees ಪಾವತಿಸಬಹುದು. ಲಭ್ಯವಿರುವ options ಎಂದರೆ College ಅಥವಾ Tuition Fee, Hostel Fee, Skill ಅಥವಾ Late Registration ಅಥವಾ Other Fee, Download Receipt, Payment Grievance ಮತ್ತು Grievance Result. ಈಗ ನಿಮ್ಮ pending balance ರೂ. ${formattedBalance}.`
      )
    }
  }

  const getFastDatabaseReply = async (text) => {
    if (isKannadaMode) {
      return null
    }

    const normalized = text.toLowerCase()

    if (/\b(final registration|registration status|registered or not|am i registered|have i registered)\b|रजिस्ट्रेशन स्टेटस|पंजीकरण|रजिस्ट्रेशन पूरा|registration/.test(normalized)) {
      const payments = await loadPaymentCache()
      const totalBalance = Array.isArray(payments)
        ? payments.reduce((sum, item) => sum + Number(item.balance || 0), 0)
        : 0

      return totalBalance > 0
        ? replyInSelectedLanguage(
          "Your final registration is still pending because there is an outstanding fee balance.",
          "आपका अंतिम रजिस्ट्रेशन अभी लंबित है, क्योंकि फीस बैलेंस बाकी है।"
        )
        : replyInSelectedLanguage(
          "Your final registration is completed successfully.",
          "आपका अंतिम रजिस्ट्रेशन सफलतापूर्वक पूरा हो चुका है।"
        )
    }

    if (/\b(fee balance|fees balance|pending fees|due fees|amount due|fee due|how much fee)\b|फीस|फी बैलेंस|बकाया|कितनी फीस|fee/.test(normalized)) {
      const payments = await loadPaymentCache()
      const totalBalance = Array.isArray(payments)
        ? payments.reduce((sum, item) => sum + Number(item.balance || 0), 0)
        : 0
      const formattedBalance = formatCurrency(totalBalance)

      return totalBalance > 0
        ? replyInSelectedLanguage(
          `Your current pending fee balance is rupees ${formattedBalance}.`,
          `आपका वर्तमान बकाया फीस बैलेंस ${formattedBalance} रुपये है।`
        )
        : replyInSelectedLanguage(
          "You do not have any pending fee balance.",
          "आपका कोई बकाया फीस बैलेंस नहीं है।"
        )
    }

    if (/\b(my courses|my subjects|what subjects do i have|what courses do i have|course details|subject details)\b|कोर्स|सब्जेक्ट|विषय/.test(normalized)) {
      const courses = await loadCoursesCache()
      if (!Array.isArray(courses) || !courses.length) {
        return replyInSelectedLanguage(
          "I could not find your registered course details right now.",
          "मुझे अभी आपके registered course details नहीं मिले।"
        )
      }

      const topCourses = courses.slice(0, 3).map((course) => course.title).filter(Boolean)
      if (!topCourses.length) {
        return replyInSelectedLanguage(
          `You currently have ${courses.length} registered courses.`,
          `आपके अभी ${courses.length} registered courses हैं।`
        )
      }

      return replyInSelectedLanguage(
        `You currently have ${courses.length} registered courses. Some of them are ${topCourses.join(", ")}.`,
        `आपके अभी ${courses.length} registered courses हैं। इनमें से कुछ हैं ${topCourses.join(", ")}।`
      )
    }

    if (/\b(usn|u s n|yu es en|uesn|yuesen|yusn|upsn|usm|usf|u s m|u s f|registration number|university number)\b|यूएसएन|रजिस्ट्रेशन नंबर/.test(normalized)) {
      const profile = await loadProfileCache()
      if (profile?.usn) {
        return replyInSelectedLanguage(
          `Your USN is ${profile.usn}.`,
          `आपका USN ${profile.usn} है।`
        )
      }
    }

    return null
  }

  const getDirectUsnReply = async (text) => {
    const normalized = (text || "").toLowerCase()
    if (!/^\s*(usn|u s n|registration number|university number)\s*[?.!]*\s*$|\b(what is|what's|tell|show|give|share|say|confirm)\b.*\b(usn|u s n|registration number|university number)\b|\bmy\s+(usn|registration number|university number)\b|यूएसएन|रजिस्ट्रेशन नंबर/.test(normalized)) {
      return null
    }

    const profile = await loadProfileCache()
    if (!profile?.usn) {
      return null
    }

    return replyInSelectedLanguage(
      `Your USN is ${profile.usn}.`,
      `आपका USN ${profile.usn} है।`,
      `Nimma USN ${profile.usn}.`
    )
  }

  const handleVoiceCommand = async (command, options = {}) => {
    if (!command) return
    const { skipSpeechRecovery = false } = options

    const trimmedCommand = command.trim()
    let cleaned = containsIndicScript(trimmedCommand)
      ? trimmedCommand
      : trimmedCommand.toLowerCase()

    if (cleaned === lastCommandRef.current) return
    lastCommandRef.current = cleaned

    cleaned = cleaned
      .replace(/\b(hi|hii|hello|hey)\b/gu, "")
      .replace(/\b(namaskara|namaskaraa|dayavittu|assistantu|voice bot)\b/g, "")
      .replace(/\bassistant\b/g, "")
      .replace(/\b(can you|could you|please)\b/g, "")
      .replace(/(नमस्ते|हेलो|असिस्टेंट|कृपया)/g, "")
      .replace(/(ನಮಸ್ಕಾರ|ದಯವಿಟ್ಟು|ಅಸಿಸ್ಟೆಂಟ್|ವಾಯ್ಸ್ ಬಾಟ್)/g, "")
      .trim()

    if (!cleaned) {
      setIsProcessing(false)
      replyImmediately(localizedText.emptyGreeting)
      return
    }

    const languageSwitch = getLanguageSwitchRequest(cleaned)
    if (languageSwitch) {
      setTranscript(cleaned)
      setIsProcessing(false)
      applyLanguageSwitch(languageSwitch.key, languageSwitch.reply)
      if (!vapiCallActiveRef.current) {
        void speak(languageSwitch.reply, { preferBrowser: true })
      }
      lastCommandRef.current = ""
      return
    }

    const intentText = normalizeVoiceIntent(cleaned)

    if (!skipSpeechRecovery && pendingSuggestionActionRef.current) {
      if (isAffirmativeRecoveryReply(intentText) || /^(open it|open that|go there|do it|sure|okay|ok)$/i.test(intentText)) {
        const suggestedPrompt = pendingSuggestionActionRef.current
        pendingSuggestionActionRef.current = null
        setIsProcessing(false)
        await handleVoiceCommand(suggestedPrompt, { skipSpeechRecovery: true })
        return
      }

      if (isNegativeRecoveryReply(intentText)) {
        pendingSuggestionActionRef.current = null
      }
    }

    if (pendingSpeechRecoveryRef.current.active && !skipSpeechRecovery) {
      if (isAffirmativeRecoveryReply(intentText)) {
        const correctedText = pendingSpeechRecoveryRef.current.correctedText
        const displayText = pendingSpeechRecoveryRef.current.displayText
        resetPendingSpeechRecovery()
        setTranscript(displayText || correctedText)
        setIsProcessing(false)
        await handleVoiceCommand(correctedText, { skipSpeechRecovery: true })
        return
      }

      if (isNegativeRecoveryReply(intentText)) {
        resetPendingSpeechRecovery()
        setIsProcessing(false)
        setReplySource("local_recovery")
        replyImmediately(replyInSelectedLanguage(
          "Okay. Please say your question again.",
          "ठीक है। कृपया अपना सवाल फिर से कहिए।",
          "Sari. Dayavittu nimma prashne matte heli."
        ))
        return
      }

      setIsProcessing(false)
      setReplySource("local_recovery")
      replyImmediately(replyInSelectedLanguage(
        "Please say yes or no so I can confirm your question.",
        "कृपया हाँ या नहीं कहिए, ताकि मैं आपके सवाल की पुष्टि कर सकूँ।",
        "Dayavittu haudu athava illa heli, nanu nimma prashneyannu khachitapadisabahudu."
      ))
      return
    }

    if (!skipSpeechRecovery) {
      const recoverySuggestion = getSpeechRecoverySuggestion(cleaned)
      if (recoverySuggestion && recoverySuggestion.correctedText !== intentText) {
        pendingSpeechRecoveryRef.current = {
          active: true,
          correctedText: recoverySuggestion.correctedText,
          displayText: recoverySuggestion.displayText
        }
        setIsProcessing(false)
        setReplySource("local_recovery")
        replyImmediately(replyInSelectedLanguage(
          `Did you mean ${recoverySuggestion.displayText}? Please say yes or no.`,
          `क्या आपका मतलब ${recoverySuggestion.displayText} था? कृपया हाँ या नहीं कहिए।`,
          `${recoverySuggestion.displayText} andre nimma artha? Dayavittu haudu athava illa heli.`
        ))
        return
      }
    }

    const directUsnReply = await getDirectUsnReply(intentText)
    if (directUsnReply) {
      setIsProcessing(false)
      setReplySource("local_usn")
      replyImmediately(directUsnReply)
      return
    }

    const resultSupport = await getGuidedResultSupportReply(intentText)
    if (resultSupport?.type === "reply") {
      setIsProcessing(false)
      setReplySource("local_result")
      replyImmediately(resultSupport.reply)
      return
    }

    if (resultSupport?.type === "navigate_result") {
      setIsProcessing(false)
      setReplySource("local_result")
      setResponse(resultSupport.reply)
      speak(resultSupport.reply)
      const params = new URLSearchParams(resultSupport.selection || {})
      setTimeout(() => navigate(`/results?${params.toString()}`), 800)
      lastPageRef.current = "results"
      lastCommandRef.current = ""
      return
    }

    const attendanceAnalyticsSupport = await getAttendanceAnalyticsSupportReply(intentText)
    if (attendanceAnalyticsSupport?.type === "navigate") {
      setIsProcessing(false)
      setReplySource("local_attendance_graph")
      setResponse(attendanceAnalyticsSupport.reply)
      speak(attendanceAnalyticsSupport.reply)
      setTimeout(() => navigate("/attendance-analytics"), 800)
      lastPageRef.current = "attendance-analytics"
      lastCommandRef.current = ""
      return
    }

    const paymentSupport = await getPaymentSupportReply(intentText)
    if (paymentSupport?.type === "navigate") {
      setIsProcessing(false)
      setResponse(paymentSupport.reply)
      speak(paymentSupport.reply)
      setTimeout(() => navigate("/payment"), 800)
      lastPageRef.current = "payment"
      lastCommandRef.current = ""
      return
    }

    if (paymentSupport?.type === "reply") {
      setIsProcessing(false)
      setReplySource("local_payment")
      replyImmediately(paymentSupport.reply)
      return
    }

    const normalizedForNav = ` ${cleaned} `
    const isStudentUser = currentUser?.role_key === "student"
    const isStaffUser = currentUser?.role_key && currentUser.role_key !== "student"
    const hasNavVerb =
      /\b(open|go|navigate|take me|show me|bring me|move to|launch|visit|come back|go back|back|return|hogu|open madi|torisu|tere)\b/.test(cleaned) ||
      /ಹೋಗು|ತೆರೆ|ತೋರಿಸು|ಓಪನ್/u.test(cleaned) ||
      cleaned.includes("ಹೋಗು") ||
      cleaned.includes("ಹೋಗ್ಬು") ||
      cleaned.includes("ತೆರೆ") ||
      cleaned.includes("ತೋರಿಸು") ||
      cleaned.includes("ಪೇಜ್")
    const hasDashboardWord =
      /\b(dashboard|dash board|dashbourd|dashbord)\b/.test(cleaned) ||
      /ಡ್ಯಾಶ್.?ಬೋರ್ಡ್|डैशबोर्ड/u.test(cleaned) ||
      cleaned.includes("ಡ್ಯಾಶ್‌ಬೋರ್ಡ್") ||
      cleaned.includes("ಡ್ಯಾಶ್ಬೋರ್ಡ್")
    const hasProfileWord =
      /\b(profile|profle|profail)\b/.test(cleaned) ||
      /ಪ್ರೊಫೈಲ್|प्रोफाइल/u.test(cleaned) ||
      cleaned.includes("ಪ್ರೊಫೈಲ್")
    const hasRegistrationWord =
      /\b(registration|register|rijistreshan)\b/.test(cleaned) ||
      /ರಿಜಿಸ್ಟ್ರೇಶನ್|ನೋಂದಣಿ|रजिस्ट्रेशन|पंजीकरण/u.test(cleaned) ||
      cleaned.includes("ರಿಜಿಸ್ಟ್ರೇಷನ್") ||
      cleaned.includes("ರಿಜಿಸ್ಟ್ರೇಶನ್") ||
      cleaned.includes("ನೋಂದಣಿ")
    const hasHomeWord =
      /\b(home|main page|back to main|come back|go back|return home)\b/.test(cleaned) ||
      /ಹೋಮ್|होम/u.test(cleaned)

    if (hasNavVerb) {
      if (hasHomeWord) {
        const target = isStaffUser ? "portal" : "home"
        const message = replyInSelectedLanguage(
          isStaffUser ? "Opening your role portal." : "Opening your home page.",
          isStaffUser ? "Aapka role portal khol raha hoon." : "Aapka home page khol raha hoon.",
          isStaffUser ? "Nimma role portal tereyuttiddene." : "Nimma home page tereyuttiddene."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        setTimeout(() => navigate("/" + target), 800)
        lastPageRef.current = target
        lastCommandRef.current = ""
        return
      }

      if (hasDashboardWord) {
        const message = replyInSelectedLanguage(
          isStudentUser ? "Opening your dashboard." : "Opening your role dashboard.",
          isStudentUser ? "आपका dashboard खोल रहा हूं।" : "आपका role dashboard खोल रहा हूं।",
          isStudentUser ? "ನಿಮ್ಮ ಡ್ಯಾಶ್‌ಬೋರ್ಡ್ ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "ನಿಮ್ಮ role dashboard ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        setTimeout(() => navigate("/" + (isStudentUser ? "dashboard" : "portal")), 800)
        lastPageRef.current = isStudentUser ? "dashboard" : "portal"
        lastCommandRef.current = ""
        return
      }

      if (hasProfileWord) {
        const target = isStudentUser ? "profile" : "portal"
        const message = replyInSelectedLanguage(
          isStudentUser ? "Opening your profile page." : "Opening your role portal.",
          isStudentUser ? "आपका profile page खोल रहा हूं।" : "आपका role portal खोल रहा हूं।",
          isStudentUser ? "ನಿಮ್ಮ profile page ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        setTimeout(() => navigate("/" + target), 800)
        lastPageRef.current = target
        lastCommandRef.current = ""
        return
      }

      if (hasRegistrationWord && !/\b(status|complete|completed|pending|final)\b/.test(normalizedForNav)) {
        const target = isStudentUser ? "registration" : "portal"
        const message = replyInSelectedLanguage(
          isStudentUser ? "Opening your registration page." : "Registration is student-only. Opening your role portal instead.",
          isStudentUser ? "आपका registration page खोल रहा हूं।" : "Registration अभी student-only page है। मैं आपका role portal खोल रहा हूं।",
          isStudentUser ? "ನಿಮ್ಮ registration page ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "Registration student-gagi matra ide. ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        setTimeout(() => navigate("/" + target), 800)
        lastPageRef.current = target
        lastCommandRef.current = ""
        return
      }

      if (hasHomeWord) {
        const target = isStaffUser ? "portal" : "home"
        const message = replyInSelectedLanguage(
          isStaffUser ? "Opening your role portal." : "Opening your home page.",
          isStaffUser ? "आपका role portal खोल रहा हूं।" : "आपका home page खोल रहा हूं।",
          isStaffUser ? "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "ನಿಮ್ಮ home page ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        setTimeout(() => navigate("/" + target), 800)
        lastPageRef.current = target
        lastCommandRef.current = ""
        return
      }
    }

    const openPageFromVoice = (page, englishMessage, hindiMessage, kannadaMessage) => {
      setIsProcessing(false)
      const message = replyInSelectedLanguage(englishMessage, hindiMessage, kannadaMessage)
      setResponse(message)
      speak(message)

      setTimeout(() => {
        navigate("/" + page)
      }, 800)

      lastPageRef.current = page
      lastCommandRef.current = ""
    }

    const hasAnyText = (patterns) => patterns.some((pattern) => pattern.test(cleaned))
    const asksToOpenPage = hasAnyText([
      /\b(open|go|navigate|take me|show me|bring me|move to|launch|visit|come back|go back|back|return)\b/,
      /\b(khol|kholo|kolo|karo|javu|jao|jau|chalo|dikhao|dikhavo|tere|tereyiri|open madi|hogu|torisu|show madi)\b/,
      /\bpage\b/,
      /(ತೆರೆ|ತೆರೆಯಿರಿ|ತೋರಿಸು|ತೋರಿಸಿ|ಹೋಗು|ಪುಟ|ಓಪನ್ ಮಾಡು|ಓಪನ್ ಮಾಡಿ)/u,
      /खोल|खोलो|दिखाओ|जाओ|चलो|पेज/
    ])
    const asksStatusOnly = hasAnyText([
      /\b(status|complete|completed|pending|done|finished|registered or not|am i registered|have i registered)\b/,
      /(ಸ್ಥಿತಿ|ಪೂರ್ಣ|ಕಂಪ್ಲೀಟ್|ಪೆಂಡಿಂಗ್)/u,
      /स्टेटस|स्थिति|पूरा|पेंडिंग/
    ])
    const isStudent = currentUser?.role_key === "student"
    const isStaff = currentUser?.role_key && currentUser.role_key !== "student"
    const requestedPage = (() => {
      if (/ಪ್ರೊಫೈಲ್|profile|profail/u.test(cleaned)) return "profile"
      if (/ಡ್ಯಾಶ್.?ಬೋರ್ಡ್|dashboard/u.test(cleaned)) return "dashboard"
      if (/ಹೋಮ್|home/u.test(cleaned)) return isStaff ? "portal" : "home"
      if (/ಪೋರ್ಟಲ್|portal/u.test(cleaned)) return "portal"
      if (/certificate|competency|competence|ಸರ್ಟಿಫಿಕೇಟ್|ಡಿಜಿಟಲ್/u.test(cleaned)) return "certificate"
      if (/ರಿಜಿಸ್ಟ್ರೇಶನ್|ನೋಂದಣಿ|rijistreshan/u.test(cleaned) && (asksToOpenPage || !asksStatusOnly)) {
        return isStudent ? "registration" : "portal"
      }
      if (hasAnyText([/\b(profile|profle)\b/, /प्रोफाइल/])) return "profile"
      if (hasAnyText([/\b(dashboard|dash board)\b/, /डैशबोर्ड/])) return "dashboard"
      if (hasAnyText([/\b(home|main page|back to main|come back|go back|return home)\b/, /होम/])) return isStaff ? "portal" : "home"
      if (hasAnyText([/\b(portal|role portal)\b/, /पोर्टल/])) return "portal"
      if (hasAnyText([/\b(certificate|competency|competence|digital certificate)\b/, /सर्टिफिकेट/])) return "certificate"
      if (
        hasAnyText([
          /\b(registration|register|registation|ragistration|registration number|register number|registration nuber|register nuber)\b/,
          /रजिस्ट्रेशन|पंजीकरण/
        ]) &&
        (asksToOpenPage || !asksStatusOnly)
      ) {
        return isStudent ? "registration" : "portal"
      }
      return ""
    })()

    if (requestedPage && asksToOpenPage) {
      if (requestedPage === "profile") {
        openPageFromVoice("profile", "Opening your profile page.", "आपका profile page खोल रहा हूं।", "ನಿಮ್ಮ profile page ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
        return
      }

      if (requestedPage === "dashboard") {
        openPageFromVoice("dashboard", "Opening your dashboard.", "आपका dashboard खोल रहा हूं।", "ನಿಮ್ಮ dashboard ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
        return
      }

      if (requestedPage === "registration" && isStudent) {
        openPageFromVoice("registration", "Opening your registration page.", "आपका registration page खोल रहा हूं।", "ನಿಮ್ಮ registration page ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
        return
      }

      if (requestedPage === "certificate") {
        openPageFromVoice("certificate", "Opening your digital competency certificate page.", "आपका digital competency certificate page खोल रहा हूं।", "ನಿಮ್ಮ digital competency certificate page ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
        return
      }

      if (requestedPage === "home") {
        openPageFromVoice("home", "Opening your home page.", "आपका home page खोल रहा हूं।", "ನಿಮ್ಮ home page ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
        return
      }

      openPageFromVoice("portal", "Opening your role portal.", "आपका role portal खोल रहा हूं।", "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
      return
    }

    const localProfileReply = getLocalProfileReply(intentText)
    if (localProfileReply) {
      setIsProcessing(false)
      setReplySource("local_profile")
      replyImmediately(localProfileReply)
      return
    }

    const fastNaturalReply = getFastNaturalReply(intentText)
    if (fastNaturalReply) {
      setIsProcessing(false)
      setReplySource("fast_natural")
      replyImmediately(fastNaturalReply)
      return
    }

    const fastDatabaseReply = await getFastDatabaseReply(intentText)
    if (fastDatabaseReply) {
      setIsProcessing(false)
      setReplySource("fast_db")
      replyImmediately(fastDatabaseReply)
      return
    }

    const goToPage = (page, message) => {
      setResponse(message)
      speak(message)

      setTimeout(() => {
        navigate("/" + page)
      }, 800)

      lastPageRef.current = page
      lastCommandRef.current = ""
    }

    const isNavigationRequest = /\b(open|go|navigate|take me|show me|bring me|move to|come back|go back|back|return)\b|खोलो|खोल दीजिए|दिखाओ|ले चलो|जाओ/.test(cleaned)
    const isRegistrationStatusQuery = /\b(registration status|registration complete|registration completed|registration pending|final registration|is my registration|have i registered|am i registered|registered or not)\b|रजिस्ट्रेशन स्टेटस|पंजीकरण स्थिति|रजिस्ट्रेशन पूरा|रजिस्ट्रेशन पेंडिंग/.test(cleaned)

    if (
      cleaned.includes("open it") ||
      cleaned.includes("go there") ||
      cleaned.includes("open that") ||
      cleaned.includes("वह खोलो") ||
      cleaned.includes("उसे खोलो")
    ) {
      if (lastPageRef.current) {
        setIsProcessing(false)
        goToPage(lastPageRef.current, replyInSelectedLanguage(`Opening ${lastPageRef.current}.`, `${lastPageRef.current} खोल रहा हूं।`, `${lastPageRef.current} ತೆರೆಯುತ್ತಿದ್ದೇನೆ.`))
      } else {
        setIsProcessing(false)
        replyImmediately(replyInSelectedLanguage("Please tell me which page to open.", "कृपया बताइए कौन सा पेज खोलना है।", "ಯಾವ page ತೆರೆಯಬೇಕು ಎಂದು ಹೇಳಿ."))
      }
      return
    }

    if ((cleaned.match(/\bprofile\b/) || /प्रोफाइल/.test(cleaned)) && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("profile", replyInSelectedLanguage("Opening your profile page.", "आपका profile page खोल रहा हूं।", "ನಿಮ್ಮ profile page ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      } else {
        goToPage("portal", replyInSelectedLanguage("Opening your role portal.", "आपका role portal खोल रहा हूं।", "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      }
      return
    }

    if ((cleaned.match(/\bdashboard\b/) || /डैशबोर्ड/.test(cleaned)) && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("dashboard", replyInSelectedLanguage("Opening your dashboard.", "आपका dashboard खोल रहा हूं।", "ನಿಮ್ಮ dashboard ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      } else {
        goToPage("portal", replyInSelectedLanguage("Opening your role dashboard.", "आपका role dashboard खोल रहा हूं।", "ನಿಮ್ಮ role dashboard ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      }
      return
    }

    if ((cleaned.match(/\bregistration\b/) || /रजिस्ट्रेशन|पंजीकरण/.test(cleaned)) && !isRegistrationStatusQuery && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("registration", replyInSelectedLanguage("Opening your registration page.", "आपका registration page खोल रहा हूं।", "ನಿಮ್ಮ registration page ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      } else {
        const staffReply = replyInSelectedLanguage(
          "Registration is currently a student-only page. Opening your role portal instead.",
          "Registration अभी student-only page है। मैं आपका role portal खोल रहा हूं।",
          "Registration student-gagi matra ide. ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        goToPage("portal", staffReply)
      }
      return
    }

    if ((cleaned.match(/\b(certificate|competency|competence)\b/) || /सर्टिफिकेट/.test(cleaned)) && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("certificate", replyInSelectedLanguage("Opening your digital competency certificate page.", "आपका digital competency certificate page खोल रहा हूं।", "ನಿಮ್ಮ digital competency certificate page ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      } else {
        goToPage("portal", replyInSelectedLanguage("Opening your role portal.", "आपका role portal खोल रहा हूं।", "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."))
      }
      return
    }

    if ((cleaned.match(/\bhome\b/) || /होम/.test(cleaned)) && isNavigationRequest) {
      setIsProcessing(false)
      goToPage(
        isStaff ? "portal" : "home",
        isStaff
          ? replyInSelectedLanguage("Opening your role portal.", "आपका role portal खोल रहा हूं।", "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
          : replyInSelectedLanguage("Opening your home page.", "आपका home page खोल रहा हूं।", "ನಿಮ್ಮ home page ತೆರೆಯುತ್ತಿದ್ದೇನೆ.")
      )
      return
    }

    if (/^(how are you|who are you|thank you|thanks|good morning|good afternoon|good evening|bye|goodbye|see you|कैसे हो|आप कौन हैं|धन्यवाद|शुक्रिया|नमस्ते|बाय)$/.test(cleaned)) {
      const replyPayload = await askAI(intentText)
      presentAssistantReply(replyPayload)
      return
    }

    setIsProcessing(true)
    setResponse(localizedText.thinking)
    setSuggestionText("")
    setQuickActions([])

    const replyPayload = await askAI(intentText)

    setIsProcessing(false)
    presentAssistantReply(replyPayload)
  }

  useEffect(() => {
    return () => {
      cleanupRecorder()
      void stopInterruptMonitor()
      void stopStreamingTts({ clearRemote: false })
      cleanupAudio()
      window.speechSynthesis.cancel()
    }
  }, [])

  const activateAssistant = () => {
    if (isActiveRef.current) return

    setIsActive(true)
    isActiveRef.current = true
    setErrorMessage("")
    setIsProcessing(false)
    setIsSpeaking(false)
    setReplySource("")
    setSuggestionText("")
    setQuickActions([])
    pendingSuggestionActionRef.current = null
    setStartupStatus(localizedText.tapToAsk)
    resetPendingSpeechRecovery()
    resetPendingResultQuery()

    const firstName = (currentUser?.full_name || "").trim().split(/\s+/)[0] || ""
    const welcome = firstName
      ? replyInSelectedLanguage(
        `Hello ${firstName}. I am GMU VoiceBot. Tap again and ask your question.`,
        `नमस्ते ${firstName}। मैं GMU VoiceBot हूं। फिर से दबाकर अपना सवाल पूछिए।`
      )
      : replyInSelectedLanguage(
        "Hello. I am GMU VoiceBot. Tap again and ask your question.",
        "नमस्ते। मैं GMU VoiceBot हूं। फिर से दबाकर अपना सवाल पूछिए।"
      )
    const welcomeMessage = isKannadaMode
      ? (
        firstName
          ? `ನಮಸ್ಕಾರ ${firstName}. ನಾನು GMU VoiceBot. ಮತ್ತೊಮ್ಮೆ ಟ್ಯಾಪ್ ಮಾಡಿ ನಿಮ್ಮ ಪ್ರಶ್ನೆಯನ್ನು ಕೇಳಿ.`
          : "ನಮಸ್ಕಾರ. ನಾನು GMU VoiceBot. ಮತ್ತೊಮ್ಮೆ ಟ್ಯಾಪ್ ಮಾಡಿ ನಿಮ್ಮ ಪ್ರಶ್ನೆಯನ್ನು ಕೇಳಿ."
      )
      : welcome
    setResponse(welcomeMessage)
    setSuggestionText("")
    setQuickActions([])
    setTranscript("")
    setReplySource("")
    void speak(welcomeMessage, { preferBrowser: languageConfig.ttsProvider !== "elevenlabs" })
  }

  const handleAssistantButtonClick = async () => {
    if (USE_VAPI_AS_PRIMARY_VOICE) {
      await toggleVapiCall()
      return
    }

    if (!isActiveRef.current) {
      activateAssistant()
      return
    }

    if (isListening || isProcessing) {
      return
    }

    if (isSpeakingRef.current) {
      await stopCurrentSpeech()
    }

    setResponse(localizedText.listening)
    setReplySource("")
    await startListening()
  }

  const closeAssistant = () => {
    setIsActive(false)
    isActiveRef.current = false
    isSpeakingRef.current = false
    lastSpokenTextRef.current = ""
    isProcessingRef.current = false
    setIsProcessing(false)
    setIsSpeaking(false)
    setReplySource("")
    setSuggestionText("")
    setQuickActions([])
    pendingSuggestionActionRef.current = null
    setStartupStatus("")
    resetPendingSpeechRecovery()
    resetPendingResultQuery()
    cleanupRecorder()
    stopVapiCall()
    void stopCurrentSpeech()
    cleanupAudio()
    window.speechSynthesis.cancel()
  }

  const statusLabel = isSpeaking
    ? localizedText.speaking
    : isListening
      ? localizedText.listeningStatus
      : isProcessing
        ? localizedText.thinking
        : startupStatus || localizedText.tapToAsk
  const showTapHint = isActive && !isListening && !isProcessing && !isSpeaking && !isVapiCallActive

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>x</button>

          <h3>GMU VoiceBot</h3>

          <div className="voice-content">
            <p><b>{localizedText.status}</b> {statusLabel}</p>
            <p><b>{localizedText.you}</b> {transcript}</p>
            <p><b>{localizedText.assistant}</b> {response}</p>
            {suggestionText && <p className="voice-suggestion"><b>{localizedText.suggestion}</b> {suggestionText}</p>}
            {!!quickActions.length && (
              <div className="voice-quick-actions">
                {quickActions.map((action, index) => (
                  <button
                    key={`${action.label || "action"}-${index}`}
                    type="button"
                    className="voice-quick-action-btn"
                    onClick={() => {
                      const prompt = action?.prompt?.trim()
                      if (!prompt) return
                      setTranscript(prompt)
                      setSuggestionText("")
                      setQuickActions([])
                      pendingSuggestionActionRef.current = null
                      void handleVoiceCommand(prompt)
                    }}
                  >
                    {action.label || action.prompt}
                  </button>
                ))}
              </div>
            )}
            {replySource && replySource !== "local_ui" && <p><b>{localizedText.source}</b> {replySource}</p>}
            {errorMessage && <p style={{ color: "red" }}>{errorMessage}</p>}
            {showTapHint && <p className="voice-hint">{localizedText.hint}</p>}
          </div>
        </div>
      )}

      <button
        className="voice-assistant-btn"
        onClick={handleAssistantButtonClick}
        title={isVapiCallActive ? "Stop voice session" : isActive ? localizedText.tapToAsk : localizedText.openAssistant}
        aria-label={isVapiCallActive ? "Stop voice session" : isActive ? localizedText.askAria : localizedText.openAssistant}
      >
        <img src={gmuLogo} alt="GMU VoiceBot" className="voice-logo" />
      </button>
      {showTapHint && <div className="voice-action-badge">{localizedText.badge}</div>}
    </div>
  )
}

export default VoiceAssistant


