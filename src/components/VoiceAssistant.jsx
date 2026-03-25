import { useEffect, useRef, useState } from "react"
import { useNavigate } from "react-router-dom"
import gmuLogo from "../assets/gmu-logo.png"
import "./VoiceAssistant.css"
import { fetchJson, getBackendUrl } from "../utils/api"

const MAX_RECORDING_MS = 4500
const MIN_RECORDING_MS = 400
const SILENCE_DURATION_MS = 450
const SILENCE_THRESHOLD = 0.018
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
  "thalia"
]

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

  const audioRef = useRef(null)
  const audioUrlRef = useRef(null)
  const mediaRecorderRef = useRef(null)
  const streamRef = useRef(null)
  const listenTimeoutRef = useRef(null)
  const audioContextRef = useRef(null)
  const analyserRef = useRef(null)
  const sourceNodeRef = useRef(null)
  const silenceAnimationRef = useRef(null)
  const speechDetectedRef = useRef(false)
  const silenceStartedAtRef = useRef(null)
  const recordingStartedAtRef = useRef(0)
  const ignoreNextRecordingRef = useRef(false)
  const isSpeakingRef = useRef(false)
  const lastSpokenTextRef = useRef("")
  const isActiveRef = useRef(false)
  const isProcessingRef = useRef(false)
  const lastPageRef = useRef(null)
  const lastCommandRef = useRef("")

  const navigate = useNavigate()

  useEffect(() => {
    isActiveRef.current = isActive
  }, [isActive])

  useEffect(() => {
    isProcessingRef.current = isProcessing
  }, [isProcessing])

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

    if (listenTimeoutRef.current) {
      clearTimeout(listenTimeoutRef.current)
      listenTimeoutRef.current = null
    }

    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== "inactive") {
      if (ignoreTranscript) {
        ignoreNextRecordingRef.current = true
      }
      mediaRecorderRef.current.stop()
    }

    mediaRecorderRef.current = null

    if (silenceAnimationRef.current) {
      cancelAnimationFrame(silenceAnimationRef.current)
      silenceAnimationRef.current = null
    }

    if (sourceNodeRef.current) {
      sourceNodeRef.current.disconnect()
      sourceNodeRef.current = null
    }

    if (analyserRef.current) {
      analyserRef.current.disconnect()
      analyserRef.current = null
    }

    if (audioContextRef.current) {
      audioContextRef.current.close().catch(() => {})
      audioContextRef.current = null
    }

    speechDetectedRef.current = false
    silenceStartedAtRef.current = null
    recordingStartedAtRef.current = 0

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

  const finishSpeaking = () => {
    isSpeakingRef.current = false
    setIsSpeaking(false)
    setStartupStatus("")
  }

  const transcribeAudio = async (audioBlob) => {
    const formData = new FormData()
    formData.append("audio", audioBlob, "voice.webm")

    const data = await fetchJson("deepgramTranscribe.php", {
      method: "POST",
      body: formData
    })

    return (data.transcript || "").trim()
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
      setErrorMessage("Microphone recording is not supported in this browser.")
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

      const mimeType = MediaRecorder.isTypeSupported("audio/webm;codecs=opus")
        ? "audio/webm;codecs=opus"
        : ""

      const recorder = mimeType
        ? new MediaRecorder(stream, { mimeType })
        : new MediaRecorder(stream)

      const chunks = []

      recorder.ondataavailable = (event) => {
        if (event.data.size) {
          chunks.push(event.data)
        }
      }

      recorder.onstop = async () => {
        const audioBlob = new Blob(chunks, {
          type: recorder.mimeType || "audio/webm"
        })

        cleanupRecorder()

        if (ignoreNextRecordingRef.current) {
          ignoreNextRecordingRef.current = false
          return
        }

        if (!audioBlob.size || !isActiveRef.current) {
          return
        }

        try {
          const text = await transcribeAudio(audioBlob)

          if (!text) {
            setTranscript("")
            setResponse("I did not catch that. Tap the voice button and try again.")
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          if (isSelfTranscript(text)) {
            setTranscript(text.toLowerCase())
            setResponse("Tap the voice button when you are ready with your next question.")
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          const cleanedText = text.toLowerCase()
          setTranscript(cleanedText)
          await handleVoiceCommand(cleanedText)
        } catch (error) {
          setErrorMessage(error.message || "Unable to transcribe audio.")
        }
      }

      streamRef.current = stream
      mediaRecorderRef.current = recorder
      recordingStartedAtRef.current = Date.now()
      setErrorMessage("")
      setIsListening(true)
      recorder.start()

      try {
        const audioContext = new window.AudioContext()
        const analyser = audioContext.createAnalyser()
        const sourceNode = audioContext.createMediaStreamSource(stream)

        analyser.fftSize = 2048
        sourceNode.connect(analyser)

        audioContextRef.current = audioContext
        analyserRef.current = analyser
        sourceNodeRef.current = sourceNode

        const timeData = new Uint8Array(analyser.fftSize)

        const monitorSilence = () => {
          if (!mediaRecorderRef.current || mediaRecorderRef.current.state === "inactive") {
            silenceAnimationRef.current = null
            return
          }

          analyser.getByteTimeDomainData(timeData)

          let sumSquares = 0
          for (let index = 0; index < timeData.length; index += 1) {
            const normalized = (timeData[index] - 128) / 128
            sumSquares += normalized * normalized
          }

          const rms = Math.sqrt(sumSquares / timeData.length)
          const now = Date.now()
          const elapsed = now - recordingStartedAtRef.current

          if (rms >= SILENCE_THRESHOLD) {
            speechDetectedRef.current = true
            silenceStartedAtRef.current = null
          } else if (speechDetectedRef.current && elapsed >= MIN_RECORDING_MS) {
            if (!silenceStartedAtRef.current) {
              silenceStartedAtRef.current = now
            }

            if (now - silenceStartedAtRef.current >= SILENCE_DURATION_MS) {
              mediaRecorderRef.current.stop()
              silenceAnimationRef.current = null
              return
            }
          }

          silenceAnimationRef.current = requestAnimationFrame(monitorSilence)
        }

        silenceAnimationRef.current = requestAnimationFrame(monitorSilence)
      } catch {
        // Fall back to max-duration recording if Web Audio monitoring is unavailable.
      }

      listenTimeoutRef.current = setTimeout(() => {
        if (recorder.state !== "inactive") {
          recorder.stop()
        }
      }, MAX_RECORDING_MS)
    } catch (error) {
      cleanupRecorder()
      setErrorMessage(error.message || "Unable to access microphone.")
    }
  }

  const speakWithBrowserFallback = (text) => {
    if (!("speechSynthesis" in window)) {
      finishSpeaking()
      return
    }

    cleanupAudio()
    cleanupRecorder({ ignoreTranscript: true })
    window.speechSynthesis.cancel()

    const utterance = new SpeechSynthesisUtterance(text)
    const voices = window.speechSynthesis.getVoices()
    const preferredVoice = voices.find((voice) => {
      const voiceLabel = `${voice.name} ${voice.voiceURI}`.toLowerCase()
      return PREFERRED_FEMALE_VOICE_HINTS.some((hint) => voiceLabel.includes(hint))
    })

    if (preferredVoice) {
      utterance.voice = preferredVoice
    }

    utterance.lang = "en-US"
    utterance.rate = 1.04
    utterance.pitch = 1.08

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
    const { preferBrowser = false } = options

    if (!text) return

    if (preferBrowser) {
      speakWithBrowserFallback(text)
      return
    }

    cleanupRecorder({ ignoreTranscript: true })
    cleanupAudio()
    window.speechSynthesis.cancel()

    try {
      const response = await fetch(getBackendUrl("deepgramTts.php"), {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ text })
      })

      if (!response.ok) {
        throw new Error("Deepgram TTS request failed.")
      }

      const audioBlob = await response.blob()

      if (!audioBlob.size) {
        throw new Error("Deepgram TTS returned empty audio.")
      }

      lastSpokenTextRef.current = text
      const audioUrl = URL.createObjectURL(audioBlob)
      const audio = new Audio(audioUrl)
      let didStartPlayback = false

      audioRef.current = audio
      audioUrlRef.current = audioUrl

      audio.onplay = () => {
        didStartPlayback = true
        isSpeakingRef.current = true
        setIsSpeaking(true)
      }

      audio.onplaying = () => {
        didStartPlayback = true
        isSpeakingRef.current = true
        setIsSpeaking(true)
      }

      audio.onended = () => {
        cleanupAudio()
        finishSpeaking()
      }

      audio.onerror = () => {
        cleanupAudio()
        if (!didStartPlayback) {
          speakWithBrowserFallback(text)
        } else {
          finishSpeaking()
        }
      }

      await audio.play()
    } catch {
      speakWithBrowserFallback(text)
    }
  }

  const askAI = async (text) => {
    try {
      const data = await fetchJson("api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: text })
      })
      setReplySource(data.reply_source || "unknown")
      return data.reply || "I could not find an answer."
    } catch {
      setReplySource("request_failed")
      return "Server is not responding. Please try again."
    }
  }

  const replyImmediately = (text) => {
    setResponse(text)
    setReplySource("")
    void speak(text, { preferBrowser: true })
    lastCommandRef.current = ""
  }

  const handleVoiceCommand = async (command) => {
    if (!command) return

    let cleaned = command.trim().toLowerCase()

    if (cleaned === lastCommandRef.current) return
    lastCommandRef.current = cleaned

    cleaned = cleaned
      .replace(/\b(hi|hii|hello|hey)\b/g, "")
      .replace(/\bassistant\b/g, "")
      .replace(/\b(can you|could you|please)\b/g, "")
      .trim()

    if (!cleaned) {
      replyImmediately("Hello. What can I help you with?")
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

    const isStudent = currentUser?.role_key === "student"
    const isStaff = currentUser?.role_key && currentUser.role_key !== "student"
    const isNavigationRequest = /\b(open|go|navigate|take me|show me|bring me|move to)\b/.test(cleaned)
    const isRegistrationStatusQuery = /\b(registration status|registration complete|registration completed|registration pending|final registration|is my registration|have i registered|am i registered|registered or not)\b/.test(cleaned)

    if (
      cleaned.includes("open it") ||
      cleaned.includes("go there") ||
      cleaned.includes("open that")
    ) {
      if (lastPageRef.current) {
        goToPage(lastPageRef.current, "Opening " + lastPageRef.current)
      } else {
        replyImmediately("Please tell me which page to open.")
      }
      return
    }

    if (cleaned.match(/\bprofile\b/) && isNavigationRequest) {
      if (isStudent) {
        goToPage("profile", "Opening your profile page.")
      } else {
        goToPage("portal", "Opening your role portal.")
      }
      return
    }

    if (cleaned.match(/\bdashboard\b/) && isNavigationRequest) {
      if (isStudent) {
        goToPage("dashboard", "Opening your dashboard.")
      } else {
        goToPage("portal", "Opening your role dashboard.")
      }
      return
    }

    if (cleaned.match(/\bregistration\b/) && !isRegistrationStatusQuery && isNavigationRequest) {
      if (isStudent) {
        goToPage("registration", "Opening your registration page.")
      } else {
        const staffReply = "Registration is currently a student-only page. Opening your role portal instead."
        goToPage("portal", staffReply)
      }
      return
    }

    if (cleaned.match(/\bhome\b/) && isNavigationRequest) {
      goToPage(isStaff ? "portal" : "home", isStaff ? "Opening your role portal." : "Opening your home page.")
      return
    }

    if (/^(how are you|who are you|thank you|thanks|good morning|good afternoon|good evening|bye|goodbye|see you)$/.test(cleaned)) {
      const reply = await askAI(cleaned)
      setResponse(reply)
      void speak(reply)
      lastCommandRef.current = ""
      return
    }

    setIsProcessing(true)
    setResponse("Thinking...")

    const reply = await askAI(cleaned)

    setIsProcessing(false)
    setResponse(reply)
    void speak(reply)
    lastCommandRef.current = ""
  }

  useEffect(() => {
    return () => {
      cleanupRecorder()
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
    setStartupStatus("Starting assistant...")

    const roleLabel = currentUser?.role_name ? ` for ${currentUser.role_name}` : ""
    const welcome = `Hello${currentUser?.full_name ? ` ${currentUser.full_name}` : ""}. I am GMU VoiceBot${roleLabel}, your voice assistant for profile, fees, attendance, results, and course support. How can I help you today?`
    setResponse(welcome)
    setTranscript("")
    setReplySource("")
    void speak(welcome)
  }

  const handleAssistantButtonClick = () => {
    if (!isActiveRef.current) {
      activateAssistant()
      return
    }

    if (isListening || isProcessing || isSpeakingRef.current) {
      return
    }

    setResponse("Listening for your question...")
    setReplySource("")
    void startListening()
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
    setStartupStatus("")
    cleanupRecorder()
    cleanupAudio()
    window.speechSynthesis.cancel()
  }

  const statusLabel = isSpeaking
    ? "Speaking..."
    : isListening
      ? "Listening..."
      : isProcessing
        ? "Thinking..."
        : startupStatus || "Tap to ask"
  const showTapHint = isActive && !isListening && !isProcessing && !isSpeaking

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>x</button>

          <h3>GMU VoiceBot</h3>

          <div className="voice-content">
            <p><b>Status:</b> {statusLabel}</p>
            <p><b>You:</b> {transcript}</p>
            <p><b>Assistant:</b> {response}</p>
            {replySource && replySource !== "local_ui" && <p><b>Source:</b> {replySource}</p>}
            {errorMessage && <p style={{ color: "red" }}>{errorMessage}</p>}
            {showTapHint && <p className="voice-hint">Tap the round GMU button below to ask your next question.</p>}
          </div>
        </div>
      )}

      <button
        className="voice-assistant-btn"
        onClick={handleAssistantButtonClick}
        title={isActive ? "Tap to ask" : "Open voice assistant"}
        aria-label={isActive ? "Tap to ask your question" : "Open voice assistant"}
      >
        <img src={gmuLogo} alt="GMU VoiceBot" className="voice-logo" />
      </button>
      {showTapHint && <div className="voice-action-badge">Tap to ask</div>}
    </div>
  )
}

export default VoiceAssistant
