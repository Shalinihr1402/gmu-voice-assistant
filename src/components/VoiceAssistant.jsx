import { useEffect, useRef, useState } from "react"
import { useLocation, useNavigate } from "react-router-dom"
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
const DEFAULT_SUGGESTION_CHIPS = {
  en: [
    "Show my profile",
    "What is my fee status?",
    "Tell me my attendance",
    "How do I check my result?"
  ],
  hi: [
    "मेरा प्रोफाइल दिखाओ",
    "मेरी फीस स्थिति क्या है?",
    "मेरी अटेंडेंस बताओ",
    "रिजल्ट कैसे चेक करूं?"
  ],
  kn: [
    "ನನ್ನ ಪ್ರೊಫೈಲ್ ತೋರಿಸಿ",
    "ನನ್ನ ಫೀಸ್ ಸ್ಥಿತಿ ಏನು?",
    "ನನ್ನ ಹಾಜರಾತಿ ಹೇಳಿ",
    "ರಿಸಲ್ಟ್ ಹೇಗೆ ನೋಡಬೇಕು?"
  ]
}
const FALLBACK_SUGGESTION_CHIPS = {
  profile: {
    en: ["What details are in my profile?", "Which department am I in?", "Which semester am I in now?", "Open my profile page"],
    hi: ["मेरी प्रोफाइल में कौन-कौन सी जानकारी है?", "मैं किस विभाग में हूं?", "मैं अभी किस सेमेस्टर में हूं?", "मेरा प्रोफाइल पेज खोलो"],
    kn: ["ನನ್ನ ಪ್ರೊಫೈಲ್‌ನಲ್ಲಿ ಯಾವ ಮಾಹಿತಿಯಿದೆ?", "ನಾನು ಯಾವ ವಿಭಾಗದಲ್ಲಿದ್ದೇನೆ?", "ನಾನು ಈಗ ಯಾವ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಇದ್ದೇನೆ?", "ನನ್ನ ಪ್ರೊಫೈಲ್ ಪುಟ ತೆರೆ"]
  },
  fees: {
    en: ["What is my pending fee amount?", "How can I pay my fees?", "Open the payment page", "How do I raise a payment grievance?"],
    hi: ["मेरी बकाया फीस कितनी है?", "मैं फीस कैसे भरूं?", "पेमेंट पेज खोलो", "पेमेंट grievance कैसे दर्ज करूं?"],
    kn: ["ನನ್ನ ಬಾಕಿ ಫೀಸ್ ಎಷ್ಟು ಇದೆ?", "ನಾನು ಫೀಸ್ ಹೇಗೆ ಪಾವತಿಸಬೇಕು?", "ಪೇಮೆಂಟ್ ಪುಟ ತೆರೆ", "ಪೇಮೆಂಟ್ grievance ಹೇಗೆ ಸಲ್ಲಿಸಬೇಕು?"]
  },
  attendance: {
    en: ["What is my overall attendance?", "Attendance for which subject can you check?", "Tell attendance for DBMS", "How do I improve attendance?"],
    hi: ["मेरी कुल अटेंडेंस कितनी है?", "आप किस subject की attendance बता सकते हैं?", "DBMS की attendance बताओ", "अटेंडेंस कैसे सुधारूं?"],
    kn: ["ನನ್ನ ಒಟ್ಟು ಹಾಜರಾತಿ ಎಷ್ಟು?", "ನೀವು ಯಾವ subject‌ನ ಹಾಜರಾತಿ ಹೇಳಬಹುದು?", "DBMS ಹಾಜರಾತಿ ಹೇಳಿ", "ಹಾಜರಾತಿ ಹೇಗೆ ಹೆಚ್ಚಿಸಬಹುದು?"]
  },
  results: {
    en: ["How do I check semester results?", "Can you calculate my CGPA?", "What exam options are available in result page?", "Open the result page"],
    hi: ["सेमेस्टर रिजल्ट कैसे चेक करूं?", "क्या आप मेरा CGPA निकाल सकते हैं?", "रिजल्ट पेज में कौन-कौन से exam options हैं?", "रिजल्ट पेज खोलो"],
    kn: ["ಸೆಮಿಸ್ಟರ್ ರಿಸಲ್ಟ್ ಹೇಗೆ ನೋಡಬೇಕು?", "ನನ್ನ CGPA ಲೆಕ್ಕ ಹಾಕಬಹುದಾ?", "ರಿಸಲ್ಟ್ ಪುಟದಲ್ಲಿ ಯಾವ exam options ಇವೆ?", "ರಿಸಲ್ಟ್ ಪುಟ ತೆರೆ"]
  },
  courses: {
    en: ["What courses are in my semester?", "Tell me the course code for DBMS", "Which subjects do I have now?", "What is the credit for this subject?"],
    hi: ["मेरे सेमेस्टर में कौन-कौन से courses हैं?", "DBMS का course code बताओ", "अभी मेरे कौन-कौन से subjects हैं?", "इस subject के credits कितने हैं?"],
    kn: ["ನನ್ನ ಸೆಮಿಸ್ಟರ್‌ನಲ್ಲಿ ಯಾವ ಯಾವ courses ಇವೆ?", "DBMS course code ಹೇಳಿ", "ಈಗ ನನಗೆ ಯಾವ subjects ಇವೆ?", "ಈ subject‌ಗೆ ಎಷ್ಟು credits ಇವೆ?"]
  },
  registration: {
    en: ["Is my registration completed?", "Open the registration page", "What documents do I need for registration?", "How do I check registration status?"],
    hi: ["क्या मेरी registration पूरी हो गई है?", "registration page खोलो", "registration के लिए कौन से documents चाहिए?", "registration status कैसे चेक करूं?"],
    kn: ["ನನ್ನ registration ಪೂರ್ಣಗೊಂಡಿದೆಯಾ?", "registration ಪುಟ ತೆರೆ", "registrationಗೆ ಯಾವ documents ಬೇಕು?", "registration status ಹೇಗೆ ನೋಡಬೇಕು?"]
  },
  certificates: {
    en: ["Show my competency certificates", "Open the certificate page", "Do I have any digital certificate?", "How can I download my certificate?"],
    hi: ["मेरे competency certificates दिखाओ", "certificate page खोलो", "क्या मेरे पास कोई digital certificate है?", "मैं certificate कैसे download करूं?"],
    kn: ["ನನ್ನ competency certificates ತೋರಿಸಿ", "certificate ಪುಟ ತೆರೆ", "ನನ್ನ ಬಳಿ ಯಾವುದಾದರೂ digital certificate ಇದೆಯಾ?", "certificate ಹೇಗೆ download ಮಾಡಬೇಕು?"]
  },
  general: DEFAULT_SUGGESTION_CHIPS
}

const VoiceAssistant = () => {
  const [isActive, setIsActive] = useState(false)
  const [isListening, setIsListening] = useState(false)
  const [isProcessing, setIsProcessing] = useState(false)
  const [isSpeaking, setIsSpeaking] = useState(false)
  const [transcript, setTranscript] = useState("")
  const [response, setResponse] = useState("")
  const [replySource, setReplySource] = useState("")
  const [errorMessage, setErrorMessage] = useState("")
  const [currentUser, setCurrentUser] = useState(null)
  const [startupStatus, setStartupStatus] = useState("")
  const [voiceLanguage, setVoiceLanguage] = useState(getStoredUiLanguage())
  const [activeSuggestionTopic, setActiveSuggestionTopic] = useState("")

  const audioRef = useRef(null)
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
  const interactionIdRef = useRef(0)
  const aiAbortControllerRef = useRef(null)
  const ttsAbortControllerRef = useRef(null)
  const navigationTimeoutRef = useRef(null)
  const previousPathnameRef = useRef(null)

  const navigate = useNavigate()
  const location = useLocation()
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
    assistant: "Assistant:"
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
  }

  const replyInSelectedLanguage = (english, hindi, kannada) => (
    isKannadaMode ? (kannada || english) : isHindiMode ? hindi : english
  )
  const getSuggestionChips = (language, topic = "") => {
    const normalizedLanguage = VOICE_LANGUAGE_OPTIONS[language] ? language : "en"
    const topicKey = topic && FALLBACK_SUGGESTION_CHIPS[topic] ? topic : "general"

    if (topicKey === "general" && !topic) {
      return DEFAULT_SUGGESTION_CHIPS[normalizedLanguage] || DEFAULT_SUGGESTION_CHIPS.en
    }

    return (
      FALLBACK_SUGGESTION_CHIPS[topicKey]?.[normalizedLanguage]
      || FALLBACK_SUGGESTION_CHIPS.general[normalizedLanguage]
      || DEFAULT_SUGGESTION_CHIPS.en
    )
  }

  const detectSuggestionTopic = (text = "", intent = "", replySourceValue = "") => {
    const normalized = String(text || "").trim().toLowerCase()
    const normalizedIntent = String(intent || "").toUpperCase()
    const normalizedReplySource = String(replySourceValue || "").toLowerCase()

    if (normalizedIntent.includes("ATTENDANCE") || /\b(attendance|present|absent|percentage)\b|हाजिरी|अटेंडेंस|ಹಾಜರಾತಿ/u.test(normalized)) {
      return "attendance"
    }

    if (
      normalizedIntent.includes("FEE")
      || normalizedIntent.includes("PAYMENT")
      || /\b(fee|fees|payment|paid|due|balance|grievance)\b|फीस|पेमेंट|भुगतान|ಫೀಸ್|ಪೇಮೆಂಟ್|ಗ್ರೀವನ್ಸ್/u.test(normalized)
    ) {
      return "fees"
    }

    if (
      normalizedIntent.includes("RESULT")
      || normalizedIntent.includes("CGPA")
      || normalizedIntent.includes("SGPA")
      || /\b(result|results|marks|cgpa|sgpa|grade|exam)\b|रिजल्ट|मार्क्स|सीजीपीए|एसजीपीए|ರಿಸಲ್ಟ್|ಅಂಕ|ಸಿಜಿಪಿಎ/u.test(normalized)
    ) {
      return "results"
    }

    if (
      normalizedIntent.includes("COURSE")
      || normalizedIntent.includes("SUBJECT")
      || /\b(course|courses|subject|subjects|syllabus|credit|credits|dbms|os|cn|ai)\b|कोर्स|सब्जेक्ट|विषय|ಕೋರ್ಸ್|ವಿಷಯ/u.test(normalized)
    ) {
      return "courses"
    }

    if (normalizedIntent.includes("CERTIFICATE") || /\b(certificate|certificates|competency|digital certificate)\b|सर्टिफिकेट|ಪ್ರಮಾಣಪತ್ರ|ಸರ್ಟಿಫಿಕೇಟ್/u.test(normalized)) {
      return "certificates"
    }

    if (normalizedIntent.includes("REGISTRATION") || /\b(registration|register|registered)\b|रजिस्ट्रेशन|पंजीकरण|ನೋಂದಣಿ|ರಿಜಿಸ್ಟ್ರೇಶನ್/u.test(normalized)) {
      return "registration"
    }

    if (
      normalizedIntent.includes("PROFILE")
      || normalizedIntent.includes("SEMESTER")
      || normalizedIntent.includes("DEPARTMENT")
      || /\b(profile|department|branch|semester|usn|who am i)\b|प्रोफाइल|विभाग|सेमेस्टर|ಪ್ರೊಫೈಲ್|ವಿಭಾಗ|ಸೆಮಿಸ್ಟರ್/u.test(normalized)
    ) {
      return "profile"
    }

    if (normalizedReplySource.includes("fallback")) {
      return "general"
    }

    return ""
  }

  const isFallbackResponse = ({ intent = "", route = "", replySourceValue = "" }) => {
    const normalizedIntent = String(intent || "").toUpperCase()
    const normalizedRoute = String(route || "").toLowerCase()
    const normalizedReplySource = String(replySourceValue || "").toLowerCase()

    return (
      normalizedReplySource.includes("fallback")
      || (normalizedRoute === "llm" && (normalizedIntent === "UNKNOWN" || normalizedIntent === "ROLE_AWARE_ASSIST"))
    )
  }

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
    fetchJson("getCurrentUser.php")
      .then((data) => {
        if (!data.error) {
          setCurrentUser(data)
        }
      })
      .catch(() => {})
  }, [])

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

  const clearPendingNavigation = () => {
    if (navigationTimeoutRef.current) {
      clearTimeout(navigationTimeoutRef.current)
      navigationTimeoutRef.current = null
    }
  }

  const invalidateCurrentInteraction = () => {
    interactionIdRef.current += 1
    clearPendingNavigation()

    if (aiAbortControllerRef.current) {
      aiAbortControllerRef.current.abort()
      aiAbortControllerRef.current = null
    }

    if (ttsAbortControllerRef.current) {
      ttsAbortControllerRef.current.abort()
      ttsAbortControllerRef.current = null
    }
  }

  const isInteractionStale = (interactionId) => interactionId !== interactionIdRef.current

  const scheduleNavigation = (path) => {
    clearPendingNavigation()
    navigationTimeoutRef.current = window.setTimeout(() => {
      navigationTimeoutRef.current = null
      navigate(path)
    }, 800)
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

  const cancelAssistantActivity = async ({ preserveActive = true } = {}) => {
    invalidateCurrentInteraction()
    isSpeakingRef.current = false
    isProcessingRef.current = false
    lastSpokenTextRef.current = ""
    setIsProcessing(false)
    setIsSpeaking(false)
    setReplySource("")
    setStartupStatus("")

    if (!preserveActive) {
      setIsActive(false)
      isActiveRef.current = false
    }

    cleanupRecorder({ ignoreTranscript: true })
    await stopCurrentSpeech()
    cleanupAudio()
    window.speechSynthesis.cancel()
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
    const cleanedText = (text || "").trim().toLowerCase()

    if (!cleanedText || transcriptSubmittedRef.current || !isActiveRef.current) {
      return
    }

    transcriptSubmittedRef.current = true
    setTranscript(cleanedText)
    await handleVoiceCommand(cleanedText)
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
    const shouldUseBrowserTts = preferBrowser || languageConfig.ttsProvider === "browser"
    const interactionId = interactionIdRef.current

    if (shouldUseBrowserTts) {
      if (bufferedText && !isInteractionStale(interactionId)) {
        speakWithBrowserFallback(bufferedText)
      }
      return
    }

    if (!bufferedText || isInteractionStale(interactionId)) {
      finishSpeaking()
      return
    }

    const playElevenLabsBlobAudio = async () => {
      const controller = new AbortController()
      ttsAbortControllerRef.current = controller

      const response = await fetch(getBackendUrl("elevenlabsTts.php"), {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        signal: controller.signal,
        body: JSON.stringify({
          text: bufferedText,
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
      if (ttsAbortControllerRef.current === controller) {
        ttsAbortControllerRef.current = null
      }

      if (isInteractionStale(interactionId)) {
        return
      }

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
      lastSpokenTextRef.current = bufferedText

      await playElevenLabsBlobAudio()
    } catch (error) {
      if (error?.name === "AbortError" || isInteractionStale(interactionId)) {
        finishSpeaking()
        return
      }

      if (isKannadaMode) {
        finishSpeaking()
        setErrorMessage(error?.message || "Kannada speech synthesis is unavailable right now.")
        return
      }

      speakWithBrowserFallback(bufferedText)
    }
  }

  const normalizeText = (text) => (
    (text || "")
      .toLowerCase()
      .replace(/[^\w\s]/g, " ")
      .replace(/\s+/g, " ")
      .trim()
  )

  const isSelfTranscript = (text) => {
    const transcriptText = normalizeText(text)
    const spokenText = normalizeText(lastSpokenTextRef.current)

    if (!transcriptText || !spokenText) {
      return false
    }

    if (transcriptText === spokenText) {
      return true
    }

    if (spokenText.includes(transcriptText) || transcriptText.includes(spokenText)) {
      return true
    }

    const transcriptWords = transcriptText.split(" ").filter(Boolean)
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
          setTranscript(combinedTranscript.toLowerCase())

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
            setTranscript(finalText.toLowerCase())
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
            setTranscript(finalText.toLowerCase())
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

    const utterance = new SpeechSynthesisUtterance(text)
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
    utterance.rate = isHindiMode ? 0.96 : 1.04
    utterance.pitch = isHindiMode ? 1.02 : 1.08

    utterance.onstart = () => {
      isSpeakingRef.current = true
      setIsSpeaking(true)
    }

    utterance.onend = finishSpeaking

    utterance.onerror = finishSpeaking

    lastSpokenTextRef.current = text
    window.speechSynthesis.speak(utterance)
  }

  const speak = async (text, options = {}) => {
    const { preferBrowser = languageConfig.ttsProvider !== "elevenlabs" && USE_BROWSER_TTS_BY_DEFAULT } = options

    if (!text) return
    if (!isActiveRef.current || isInteractionStale(interactionIdRef.current)) return

    await speakTextStream(text, { preferBrowser })
  }

  const askAI = async (text) => {
    try {
      const interactionId = interactionIdRef.current
      const controller = new AbortController()
      aiAbortControllerRef.current = controller
      const data = await fetchJson("api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        signal: controller.signal,
        body: JSON.stringify({ message: text, language: languageConfig.apiLanguage })
      })

      if (aiAbortControllerRef.current === controller) {
        aiAbortControllerRef.current = null
      }

      if (isInteractionStale(interactionId)) {
        return { reply: "", suggestionTopic: "" }
      }

      const nextReplySource = data.reply_source || "unknown"
      const suggestionTopic = isFallbackResponse({
        intent: data.intent,
        route: data.route,
        replySourceValue: nextReplySource
      })
        ? (detectSuggestionTopic(text, data.intent, nextReplySource) || "general")
        : ""

      setReplySource(nextReplySource)
      return {
        reply: data.reply || localizedText.noAnswer,
        suggestionTopic
      }
    } catch (error) {
      if (error?.name === "AbortError") {
        return { reply: "", suggestionTopic: "" }
      }

      setReplySource("request_failed")
      return { reply: localizedText.serverError, suggestionTopic: "" }
    }
  }

  const replyImmediately = (text) => {
    if (!text || !isActiveRef.current) {
      return
    }

    setResponse(text)
    setActiveSuggestionTopic("")
    setReplySource("")
    void speak(text, { preferBrowser: languageConfig.ttsProvider !== "elevenlabs" })
    lastCommandRef.current = ""
  }

  const handleSuggestionChipClick = async (chipText) => {
    if (!chipText || !isActiveRef.current || isListening || isProcessing) {
      return
    }

    if (isSpeakingRef.current) {
      await stopCurrentSpeech()
    }

    setTranscript(chipText)
    await handleVoiceCommand(chipText)
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

  const formatCurrency = (value) => {
    const amount = Number(value)
    if (!Number.isFinite(amount)) {
      return null
    }

    return new Intl.NumberFormat("en-IN", {
      maximumFractionDigits: 0
    }).format(amount)
  }

  const normalizeVoiceIntent = (text) => {
    let normalized = String(text || "").trim().toLowerCase()

    const replacements = [
      [/\b(shikayat|sikayat|shikayath|complaint)\b/g, " grievance "],
      [/शिकायत|शिकायात|ग्रिवेंस|ग्रीवेंस|गृवेंस|ग्रीयेवेंस/gu, " grievance "],
      [/\b(ahavalu|ahavaalu|grevans|grievans)\b/g, " grievance "],
      [/ಅಹವಾಲು|ಅಹವಾಳು|ಗ್ರೀವೆನ್ಸ್|ಗ್ರೀವನ್ಸ್|ಗ್ರಿವನ್ಸ್|ಗ್ರೀವನ್ಸ್/gu, " grievance "],
      [/\bpayment\s*(option|options|aapshan|aapshans|opshan|opshans|apshan|apshans)\b/g, "payment options"],
      [/\bpay\s*ment\s*(option|options)\b/g, "payment options"],
      [/ಪೇಮೆಂಟ್\s*(ಆಪ್ಷನ್|ಆಪ್ಷನ್ಸ್|ಆಪ್ಶನ್|ಆಪ್ಶನ್ಸ್|ಆಪ್ಷನ್ಸ್|ಆಪ್ಶನ್ಸ್)/g, " payment options "],
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
    const hasResultWord = /\b(result|results|marks|marksheet|grade sheet|gradesheet|sgpa)\b|à¤°à¤¿à¤œà¤²à¥à¤Ÿ|à¤¨à¤¤à¥€à¤œà¤¾|à¤®à¤¾à¤°à¥à¤•à¥à¤¸|à¤—à¥à¤°à¥‡à¤¡|à²«à²²à²¿à²¤à²¾à²‚à²¶|à²°à²¿à²¸à²²à³à²Ÿà³|à²®à²¾à²°à³à²•à³à²¸à³|à²—à³à²°à³‡à²¡à³/u.test(normalized)

    if (!hasResultWord) {
      return null
    }

    const isProcessQuery = /\b(how to check|how can i check|how to see|where to see|where can i see|how to get|steps|process|check result|see result)\b|à¤•à¥ˆà¤¸à¥‡|à¤•à¤¹à¤¾à¤|à¤•à¤¹à¤¾|à¤¸à¥à¤Ÿà¥‡à¤ªà¥à¤¸|à²¹à³‡à²—à³†|à²à²²à³à²²à²¿|à²¸à³à²Ÿà³†à²ªà³à²¸à³/u.test(normalized)

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

    const isInformationQuery = /\b(what is|show|tell|check|view|display|my|give)\b|à¤•à¥à¤¯à¤¾|à¤¦à¤¿à¤–à¤¾|à¤¬à¤¤à¤¾|à¤®à¥‡à¤°à¤¾|à²¨à²¨à³à²¨|à²¤à³‹à²°à²¿à²¸à³|à²¹à³‡à²³à²¿/u.test(normalized)

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

    if (semesterValue) {
      return null
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
          `I can help with your result. Please provide these details: ${missingList}. Enter USN, Semester, Exam, Year, and Season, then I can help you check it.`,
          `Main aapka result check karne mein help kar sakta hoon. Kripya ye details dijiye: ${missingList}. USN, Semester, Exam, Year, aur Season dijiye, phir main help karunga.`,
          `Nimma result ge nanu help madabahudu. Dayavittu ee details kodi: ${missingList}. USN, Semester, Exam, Year, mattu Season kodi, nantara nanu help maduttene.`
        )
      }
    }

    return null
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

      if (/\b(how to pay fees|how do i pay fees|where to pay fees|pay fees|pay my fees|fee payment|pay college fee|pay hostel fee)\b/.test(normalized)) {
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

    if (structuredIntent === "FEES_BALANCE_STEPS") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To check your fee balance, open the Registration page. In the Payment Details section, you can see total fee, paid amount, and remaining balance.",
          "फीस बैलेंस देखने के लिए रजिस्ट्रेशन पेज खोलिए। पेमेंट डिटेल्स सेक्शन में आप कुल फीस, जमा की गई राशि और बाकी बैलेंस देख सकते हैं।",
          "Fee balance nodalu Registration page tereyiri. Payment Details section nalli total fee, paid amount, mattu remaining balance nodabahudu."
        )
      }
    }

    if (structuredIntent === "FEES_BALANCE_VALUE") {
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

    if (structuredIntent === "PAY_FEES") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To pay your fees, go to the Registration page and scroll down. Click on the Payment button. In GM Smart Pay, select the required fee option and proceed.",
          "फीस भरने के लिए रजिस्ट्रेशन पेज पर जाइए और नीचे स्क्रॉल कीजिए। पेमेंट बटन दबाइए। GM Smart Pay में जरूरी फीस विकल्प चुनकर आगे बढ़िए।",
          "Fees pavatisalu Registration page ge hogi kelage scroll madi. Payment button ottiri. GM Smart Pay nalli bekaada fee option ayke madi munduvariyiri."
        )
      }
    }

    if (structuredIntent === "PAYMENT_OPTIONS") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "After clicking the Payment button, you will see options like College or Tuition Fee, Hostel Fee, Skill or Late Registration Fee, Download Receipt, Payment Grievance, and Grievance Result.",
          "पेमेंट बटन दबाने के बाद आपको College या Tuition Fee, Hostel Fee, Skill या Late Registration Fee, Download Receipt, Payment Grievance और Grievance Result जैसे विकल्प दिखेंगे।",
          "Payment button ottida mele College athava Tuition Fee, Hostel Fee, Skill athava Late Registration Fee, Download Receipt, Payment Grievance, mattu Grievance Result tara options kanisuttave."
        )
      }
    }

    if (structuredIntent === "APPLY_GRIEVANCE") {
      return {
        type: "reply",
        reply: replyInSelectedLanguage(
          "To apply for a payment grievance, go to the Registration page, click on the Payment button, then select Payment Grievance. Enter your details and submit.",
          "पेमेंट शिकायत दर्ज करने के लिए रजिस्ट्रेशन पेज पर जाइए, पेमेंट बटन दबाइए, फिर Payment Grievance चुनिए। अपनी जानकारी भरकर सबमिट कीजिए।",
          "Payment grievance haakalu Registration page ge hogi, Payment button ottiri, nantara Payment Grievance ayke madi. Nimma details tumbi submit madi."
        )
      }
    }

    if (structuredIntent === "GRIEVANCE_RESULT") {
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
      || /à²ªà²¾à²µà²¤à²¿|à²«à³€à²¸à³ à²ªà²¾à²µà²¤à²¿|à²ªà³‡à²®à³†à²‚à²Ÿà³|à²°à²¿à²¸à³€à²ªà³à²Ÿà³|à²—à³à²°à³€à²µà²¨à³à²¸à³|payment options/u.test(normalized)
      || /à¤ªà¥‡à¤®à¥‡à¤‚à¤Ÿ|à¤«à¥€à¤¸ à¤ªà¥‡à¤®à¥‡à¤‚à¤Ÿ|à¤°à¤¸à¥€à¤¦|à¤—à¥à¤°à¤¿à¤µà¥‡à¤‚à¤¸/u.test(normalized)

    if (!paymentIntent) {
      return null
    }

    if (/\b(open|go|navigate|take me|show me|visit|hogu|tere|open madi)\b/.test(normalized)
      || normalized.includes("payment page")
      || normalized.includes("payment portal")
      || normalized.includes("payment ge hogu")
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
      || /\bfee status not updated|fees status not updated|payment deducted|receipt not generated|wrong fee mapping|fee not updated\b/.test(normalized)

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

    if (/\b(usn|registration number|university number)\b|यूएसएन|रजिस्ट्रेशन नंबर/.test(normalized)) {
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

  const handleVoiceCommand = async (command) => {
    if (!command) return
    const interactionId = ++interactionIdRef.current

    let cleaned = command.trim().toLowerCase()

    if (cleaned === lastCommandRef.current) return
    lastCommandRef.current = cleaned

    cleaned = cleaned
      .replace(/\b(hi|hii|hello|hey)\b/g, "")
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

    const intentText = normalizeVoiceIntent(cleaned)

    const resultSupport = await getResultSupportReply(intentText)
    if (isInteractionStale(interactionId) || !isActiveRef.current) return
    if (resultSupport?.type === "reply") {
      setIsProcessing(false)
      setReplySource("local_result")
      replyImmediately(resultSupport.reply)
      return
    }

    const paymentSupport = await getPaymentSupportReply(intentText)
    if (isInteractionStale(interactionId) || !isActiveRef.current) return
    if (paymentSupport?.type === "navigate") {
      setIsProcessing(false)
      setActiveSuggestionTopic("")
      setResponse(paymentSupport.reply)
      speak(paymentSupport.reply)
      scheduleNavigation("/payment")
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
      /\b(open|go|navigate|take me|show me|bring me|move to|launch|visit|hogu|open madi|torisu|tere)\b/.test(cleaned) ||
      /à²¹à³‹à²—à³|à²¤à³†à²°à³†|à²¤à³‹à²°à²¿à²¸à³|à²“à²ªà²¨à³/u.test(cleaned) ||
      cleaned.includes("ಹೋಗು") ||
      cleaned.includes("ಹೋಗ್ಬು") ||
      cleaned.includes("ತೆರೆ") ||
      cleaned.includes("ತೋರಿಸು") ||
      cleaned.includes("ಪೇಜ್")
    const hasDashboardWord =
      /\b(dashboard|dash board|dashbourd|dashbord)\b/.test(cleaned) ||
      /à²¡à³à²¯à²¾à²¶à³.?à²¬à³‹à²°à³à²¡à³|à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡/u.test(cleaned) ||
      cleaned.includes("ಡ್ಯಾಶ್‌ಬೋರ್ಡ್") ||
      cleaned.includes("ಡ್ಯಾಶ್ಬೋರ್ಡ್")
    const hasProfileWord =
      /\b(profile|profle|profail)\b/.test(cleaned) ||
      /à²ªà³à²°à³Šà²«à³ˆà²²à³|à¤ªà¥à¤°à¥‹à¤«à¤¾à¤‡à¤²/u.test(cleaned) ||
      cleaned.includes("ಪ್ರೊಫೈಲ್")
    const hasRegistrationWord =
      /\b(registration|register|rijistreshan)\b/.test(cleaned) ||
      /à²°à²¿à²œà²¿à²¸à³à²Ÿà³à²°à³‡à²¶à²¨à³|à²¨à³‹à²‚à²¦à²£à²¿|à¤°à¤œà¤¿à¤¸à¥à¤Ÿà¥à¤°à¥‡à¤¶à¤¨|à¤ªà¤‚à¤œà¥€à¤•à¤°à¤£/u.test(cleaned) ||
      cleaned.includes("ರಿಜಿಸ್ಟ್ರೇಷನ್") ||
      cleaned.includes("ರಿಜಿಸ್ಟ್ರೇಶನ್") ||
      cleaned.includes("ನೋಂದಣಿ")
    const hasHomeWord =
      /\b(home|main page)\b/.test(cleaned) ||
      /à²¹à³‹à²®à³|à¤¹à¥‹à¤®/u.test(cleaned)

    if (hasNavVerb) {
      if (hasDashboardWord) {
        const message = replyInSelectedLanguage(
          isStudentUser ? "Opening your dashboard." : "Opening your role dashboard.",
          isStudentUser ? "à¤†à¤ªà¤•à¤¾ dashboard à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤" : "à¤†à¤ªà¤•à¤¾ role dashboard à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤",
          isStudentUser ? "ನಿಮ್ಮ ಡ್ಯಾಶ್‌ಬೋರ್ಡ್ ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "ನಿಮ್ಮ role dashboard ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        scheduleNavigation("/" + (isStudentUser ? "dashboard" : "portal"))
        lastPageRef.current = isStudentUser ? "dashboard" : "portal"
        lastCommandRef.current = ""
        return
      }

      if (hasProfileWord) {
        const target = isStudentUser ? "profile" : "portal"
        const message = replyInSelectedLanguage(
          isStudentUser ? "Opening your profile page." : "Opening your role portal.",
          isStudentUser ? "à¤†à¤ªà¤•à¤¾ profile page à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤" : "à¤†à¤ªà¤•à¤¾ role portal à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤",
          isStudentUser ? "ನಿಮ್ಮ profile page ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        scheduleNavigation("/" + target)
        lastPageRef.current = target
        lastCommandRef.current = ""
        return
      }

      if (hasRegistrationWord && !/\b(status|complete|completed|pending|final)\b/.test(normalizedForNav)) {
        const target = isStudentUser ? "registration" : "portal"
        const message = replyInSelectedLanguage(
          isStudentUser ? "Opening your registration page." : "Registration is student-only. Opening your role portal instead.",
          isStudentUser ? "à¤†à¤ªà¤•à¤¾ registration page à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤" : "Registration à¤…à¤­à¥€ student-only page à¤¹à¥ˆà¥¤ à¤®à¥ˆà¤‚ à¤†à¤ªà¤•à¤¾ role portal à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤",
          isStudentUser ? "ನಿಮ್ಮ registration page ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "Registration student-gagi matra ide. ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        scheduleNavigation("/" + target)
        lastPageRef.current = target
        lastCommandRef.current = ""
        return
      }

      if (hasHomeWord) {
        const target = isStaffUser ? "portal" : "home"
        const message = replyInSelectedLanguage(
          isStaffUser ? "Opening your role portal." : "Opening your home page.",
          isStaffUser ? "à¤†à¤ªà¤•à¤¾ role portal à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤" : "à¤†à¤ªà¤•à¤¾ home page à¤–à¥‹à¤² à¤°à¤¹à¤¾ à¤¹à¥‚à¤‚à¥¤",
          isStaffUser ? "ನಿಮ್ಮ role portal ತೆರೆಯುತ್ತಿದ್ದೇನೆ." : "ನಿಮ್ಮ home page ತೆರೆಯುತ್ತಿದ್ದೇನೆ."
        )
        setIsProcessing(false)
        setResponse(message)
        speak(message)
        scheduleNavigation("/" + target)
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
      scheduleNavigation("/" + page)

      lastPageRef.current = page
      lastCommandRef.current = ""
    }

    const hasAnyText = (patterns) => patterns.some((pattern) => pattern.test(cleaned))
    const asksToOpenPage = hasAnyText([
      /\b(open|go|navigate|take me|show me|bring me|move to|launch|visit)\b/,
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
      if (hasAnyText([/\b(home|main page)\b/, /होम/])) return isStaff ? "portal" : "home"
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
    if (isInteractionStale(interactionId) || !isActiveRef.current) return
    if (fastDatabaseReply) {
      setIsProcessing(false)
      setReplySource("fast_db")
      replyImmediately(fastDatabaseReply)
      return
    }

    const goToPage = (page, message) => {
      setActiveSuggestionTopic("")
      setResponse(message)
      speak(message)
      scheduleNavigation("/" + page)

      lastPageRef.current = page
      lastCommandRef.current = ""
    }

    const isNavigationRequest = /\b(open|go|navigate|take me|show me|bring me|move to)\b|खोलो|खोल दीजिए|दिखाओ|ले चलो|जाओ/.test(cleaned)
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
      const aiResponse = await askAI(intentText)
      setActiveSuggestionTopic(aiResponse.suggestionTopic || "")
      setResponse(aiResponse.reply)
      void speak(aiResponse.reply)
      lastCommandRef.current = ""
      return
    }

    setIsProcessing(true)
    setResponse(localizedText.thinking)

    const aiResponse = await askAI(intentText)
    if (isInteractionStale(interactionId) || !isActiveRef.current || !aiResponse.reply) return

    setIsProcessing(false)
    setActiveSuggestionTopic(aiResponse.suggestionTopic || "")
    setResponse(aiResponse.reply)
    void speak(aiResponse.reply)
    lastCommandRef.current = ""
  }

  useEffect(() => {
    if (previousPathnameRef.current === null) {
      previousPathnameRef.current = location.pathname
      return
    }

    if (previousPathnameRef.current !== location.pathname) {
      previousPathnameRef.current = location.pathname
      void cancelAssistantActivity()
    }
  }, [location.pathname])

  useEffect(() => {
    return () => {
      invalidateCurrentInteraction()
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
    setActiveSuggestionTopic("")
    setStartupStatus(localizedText.tapToAsk)

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
    setTranscript("")
    setReplySource("")
    void speak(welcomeMessage, { preferBrowser: languageConfig.ttsProvider !== "elevenlabs" })
  }

  const handleAssistantButtonClick = async () => {
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
    void cancelAssistantActivity({ preserveActive: false })
  }

  const statusLabel = isSpeaking
    ? localizedText.speaking
    : isListening
      ? localizedText.listeningStatus
      : isProcessing
        ? localizedText.thinking
        : startupStatus || localizedText.tapToAsk
  const showTapHint = isActive && !isListening && !isProcessing && !isSpeaking
  const suggestionChips = getSuggestionChips(voiceLanguage, activeSuggestionTopic)
  const suggestionHeading = activeSuggestionTopic
    ? replyInSelectedLanguage("Try a follow-up:", "यह अगला सवाल पूछ सकते हैं:", "ಇವುಗಳಲ್ಲಿ ಮುಂದಿನ ಪ್ರಶ್ನೆ ಕೇಳಬಹುದು:")
    : replyInSelectedLanguage("Try asking:", "यह सवाल पूछ सकते हैं:", "ಈ ಪ್ರಶ್ನೆಗಳು ಕೇಳಬಹುದು:")

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>x</button>

          <h3>GMU VoiceBot</h3>
          <div className="voice-language-toggle" aria-label="Voice language">
            {Object.entries(VOICE_LANGUAGE_OPTIONS).map(([key, option]) => (
              <button
                key={key}
                type="button"
                className={voiceLanguage === key ? "active" : ""}
                onClick={() => {
                  setVoiceLanguage(key)
                  setTranscript("")
                  setResponse("")
                  setReplySource("")
                  setStartupStatus(
                    key === "hi"
                      ? "पूछने के लिए दबाएं"
                      : key === "kn"
                        ? "ಕೇಳಲು ಒತ್ತಿಸಿ"
                        : "Tap to ask"
                  )
                  void stopCurrentSpeech()
                }}
              >
                {option.label}
              </button>
            ))}
          </div>

          <div className="voice-content">
            <p><b>{localizedText.status}</b> {statusLabel}</p>
            <p><b>{localizedText.you}</b> {transcript}</p>
            <p><b>{localizedText.assistant}</b> {response}</p>
            {replySource && replySource !== "local_ui" && <p><b>{localizedText.source}</b> {replySource}</p>}
            {errorMessage && <p style={{ color: "red" }}>{errorMessage}</p>}
            {showTapHint && <p className="voice-hint">{localizedText.hint}</p>}
            {showTapHint && suggestionChips.length > 0 && (
              <div className="voice-suggestions">
                <p className="voice-suggestions-label">{suggestionHeading}</p>
                <div className="voice-suggestion-chip-list">
                  {suggestionChips.map((chip) => (
                    <button
                      key={chip}
                      type="button"
                      className="voice-suggestion-chip"
                      onClick={() => {
                        void handleSuggestionChipClick(chip)
                      }}
                    >
                      {chip}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      <button
        className="voice-assistant-btn"
        onClick={handleAssistantButtonClick}
        title={isActive ? localizedText.tapToAsk : localizedText.openAssistant}
        aria-label={isActive ? localizedText.askAria : localizedText.openAssistant}
      >
        <img src={gmuLogo} alt="GMU VoiceBot" className="voice-logo" />
      </button>
      {showTapHint && <div className="voice-action-badge">{localizedText.badge}</div>}
    </div>
  )
}

export default VoiceAssistant
