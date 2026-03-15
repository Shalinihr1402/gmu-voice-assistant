import { useEffect, useRef, useState } from "react"
import { useNavigate } from "react-router-dom"
import gmuLogo from "../assets/gmu-logo.png"
import "./VoiceAssistant.css"
import { fetchJson, getBackendUrl } from "../utils/api"

const RECORDING_MS = 4000

const VoiceAssistant = () => {
  const [isActive, setIsActive] = useState(false)
  const [isListening, setIsListening] = useState(false)
  const [isProcessing, setIsProcessing] = useState(false)
  const [transcript, setTranscript] = useState("")
  const [response, setResponse] = useState("")
  const [errorMessage, setErrorMessage] = useState("")
  const [currentUser, setCurrentUser] = useState(null)

  const audioRef = useRef(null)
  const audioUrlRef = useRef(null)
  const mediaRecorderRef = useRef(null)
  const streamRef = useRef(null)
  const listenTimeoutRef = useRef(null)
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

  const cleanupRecorder = () => {
    if (listenTimeoutRef.current) {
      clearTimeout(listenTimeoutRef.current)
      listenTimeoutRef.current = null
    }

    if (mediaRecorderRef.current && mediaRecorderRef.current.state !== "inactive") {
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

  const transcribeAudio = async (audioBlob) => {
    const formData = new FormData()
    formData.append("audio", audioBlob, "voice.webm")

    const data = await fetchJson("deepgramTranscribe.php", {
      method: "POST",
      body: formData
    })

    return (data.transcript || "").trim()
  }

  const startListening = async () => {
    if (!isActiveRef.current || isListening || isProcessingRef.current) {
      return
    }

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

        if (!audioBlob.size || !isActiveRef.current) {
          return
        }

        try {
          const text = await transcribeAudio(audioBlob)

          if (!text) {
            const reply = "Sorry, I didn't catch that. Please try again."
            setResponse(reply)
            speak(reply)
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
      setErrorMessage("")
      setIsListening(true)
      recorder.start()

      listenTimeoutRef.current = setTimeout(() => {
        if (recorder.state !== "inactive") {
          recorder.stop()
        }
      }, RECORDING_MS)
    } catch (error) {
      cleanupRecorder()
      setErrorMessage(error.message || "Unable to access microphone.")
    }
  }

  const speakWithBrowserFallback = (text) => {
    if (!("speechSynthesis" in window)) {
      if (isActiveRef.current && !isProcessingRef.current) {
        startListening().catch(() => {})
      }
      return
    }

    cleanupAudio()
    cleanupRecorder()
    window.speechSynthesis.cancel()

    const utterance = new SpeechSynthesisUtterance(text)
    utterance.lang = "en-US"
    utterance.rate = 0.95
    utterance.pitch = 1.1

    utterance.onend = () => {
      if (isActiveRef.current && !isProcessingRef.current) {
        startListening().catch(() => {})
      }
    }

    window.speechSynthesis.speak(utterance)
  }

  const speak = async (text) => {
    if (!text) return

    cleanupRecorder()
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

      const audioUrl = URL.createObjectURL(audioBlob)
      const audio = new Audio(audioUrl)

      audioRef.current = audio
      audioUrlRef.current = audioUrl

      audio.onended = () => {
        cleanupAudio()
        if (isActiveRef.current && !isProcessingRef.current) {
          startListening().catch(() => {})
        }
      }

      audio.onerror = () => {
        cleanupAudio()
        speakWithBrowserFallback(text)
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
      return data.reply || "I could not find an answer."
    } catch {
      return "Server is not responding. Please try again."
    }
  }

  const replyImmediately = (text) => {
    setResponse(text)
    void speak(text)
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

    if (cleaned.match(/\bprofile\b/)) {
      if (isStudent) {
        goToPage("profile", "Opening your profile page.")
      } else {
        goToPage("portal", "Opening your role portal.")
      }
      return
    }

    if (cleaned.match(/\bdashboard\b/)) {
      if (isStudent) {
        goToPage("dashboard", "Opening your dashboard.")
      } else {
        goToPage("portal", "Opening your role dashboard.")
      }
      return
    }

    if (cleaned.match(/\bregistration\b/)) {
      if (isStudent) {
        goToPage("registration", "Opening your registration page.")
      } else {
        const staffReply = "Registration is currently a student-only page. Opening your role portal instead."
        goToPage("portal", staffReply)
      }
      return
    }

    if (cleaned.match(/\bhome\b/)) {
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

    const roleLabel = currentUser?.role_name ? ` for ${currentUser.role_name}` : ""
    const welcome = `Hello. I am GMU VoiceBot${roleLabel}. How can I help you?`
    setResponse(welcome)
    void speak(welcome)
  }

  const closeAssistant = () => {
    setIsActive(false)
    isActiveRef.current = false
    isProcessingRef.current = false
    setIsProcessing(false)
    cleanupRecorder()
    cleanupAudio()
    window.speechSynthesis.cancel()
  }

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>x</button>

          <h3>GMU VoiceBot</h3>

          <div className="voice-content">
            <p><b>Status:</b> {isListening ? "Listening..." : isProcessing ? "Thinking..." : "Waiting..."}</p>
            <p><b>You:</b> {transcript}</p>
            <p><b>Assistant:</b> {response}</p>
            {errorMessage && <p style={{ color: "red" }}>{errorMessage}</p>}
          </div>
        </div>
      )}

      <button className="voice-assistant-btn" onClick={activateAssistant}>
        <img src={gmuLogo} alt="GMU VoiceBot" className="voice-logo" />
      </button>
    </div>
  )
}

export default VoiceAssistant
