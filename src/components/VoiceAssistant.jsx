import { useState, useEffect, useRef } from "react"
import { useNavigate } from "react-router-dom"
import gmuLogo from "../assets/gmu-logo.png"
import "./VoiceAssistant.css"

const VoiceAssistant = () => {
  const [isActive, setIsActive] = useState(false)
  const [transcript, setTranscript] = useState("")
  const [response, setResponse] = useState("")
  const [errorMessage, setErrorMessage] = useState("")

  const recognitionRef = useRef(null)
  const femaleVoiceRef = useRef(null)
  const lastPageRef = useRef(null)
  const lastCommandRef = useRef("")

  const navigate = useNavigate()

  /* ================= LOAD FEMALE VOICE ================= */
  useEffect(() => {
    const loadVoices = () => {
      const voices = window.speechSynthesis.getVoices()
      if (!voices.length) return

      const female =
        voices.find(v => v.name.includes("Zira")) ||
        voices.find(v => v.name.includes("Samantha")) ||
        voices.find(v => v.name.toLowerCase().includes("female")) ||
        voices.find(v => v.name.toLowerCase().includes("google"))

      if (female) femaleVoiceRef.current = female
    }

    loadVoices()
    window.speechSynthesis.onvoiceschanged = loadVoices
  }, [])

  /* ================= SPEAK FUNCTION ================= */
  const speak = (text) => {
    if (!("speechSynthesis" in window)) return

    window.speechSynthesis.cancel()

    const utterance = new SpeechSynthesisUtterance(text)
    utterance.lang = "en-US"
    utterance.rate = 0.95
    utterance.pitch = 1.1

    if (femaleVoiceRef.current) {
      utterance.voice = femaleVoiceRef.current
    }

    window.speechSynthesis.speak(utterance)
  }

  /* ================= BACKEND ================= */
  const askAI = async (text) => {
    try {
      const res = await fetch(
        "http://localhost:8080/gmu-voice-assistant/backend/api.php",
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          credentials: "include",
          body: JSON.stringify({ message: text })
        }
      )

      const data = await res.json()
      return data.reply || "I could not find an answer."
    } catch {
      return "Server is not responding. Please try again."
    }
  }

 
  const handleVoiceCommand = async (command) => {
    if (!command) return

    let cleaned = command.trim().toLowerCase()

   
    if (cleaned === lastCommandRef.current) return
    lastCommandRef.current = cleaned

    
    cleaned = cleaned
      .replace(/\b(hi|hii|hello|hey)\b/g, "")
      .replace(/\bassistant\b/g, "")
      .trim()

    if (!cleaned) {
      const reply = "Hello. What can I help you with?"
      setResponse(reply)
      speak(reply)
      lastCommandRef.current = ""
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

    
    if (
      cleaned.includes("open it") ||
      cleaned.includes("go there") ||
      cleaned.includes("open that")
    ) {
      if (lastPageRef.current) {
        goToPage(lastPageRef.current, "Opening " + lastPageRef.current)
      } else {
        const reply = "Please tell me which page to open."
        setResponse(reply)
        speak(reply)
      }
      return
    }

    
    if (cleaned.match(/\bprofile\b/)) {
      goToPage("profile", "Opening your profile page.")
      return
    }

    if (cleaned.match(/\bdashboard\b/)) {
      goToPage("dashboard", "Opening your dashboard.")
      return
    }

    if (cleaned.match(/\bregistration\b/)) {
      goToPage("registration", "Opening your registration page.")
      return
    }

    if (cleaned.match(/\bhome\b/)) {
      goToPage("home", "Opening your home page.")
      return
    }

    
    const loadingReply = "Let me check that for you."
    setResponse(loadingReply)
    speak(loadingReply)

    const reply = await askAI(cleaned)

    setTimeout(() => {
      setResponse(reply)
      speak(reply)
      lastCommandRef.current = ""
    }, 600)
  }

  
  useEffect(() => {
    if (!("webkitSpeechRecognition" in window || "SpeechRecognition" in window)) {
      setErrorMessage("Speech recognition not supported in this browser")
      return
    }

    const SpeechRecognition =
      window.SpeechRecognition || window.webkitSpeechRecognition

    const recognition = new SpeechRecognition()

    recognition.continuous = true
    recognition.interimResults = false
    recognition.lang = "en-US"
    recognition.maxAlternatives = 1

    recognition.onresult = (event) => {
      const last = event.results.length - 1
      const result = event.results[last][0]

      if (result.confidence < 0.55) {
        const msg = "Sorry, I didn't catch that. Please repeat."
        setResponse(msg)
        speak(msg)
        return
      }

      const command = result.transcript.toLowerCase()
      setTranscript(command)
      handleVoiceCommand(command)
    }

    recognition.onerror = () => {}

    recognition.onend = () => {
      if (isActive) {
        setTimeout(() => {
          try {
            recognition.start()
          } catch {}
        }, 400)
      }
    }

    recognitionRef.current = recognition

    return () => recognition.stop()
  }, [isActive])


  const activateAssistant = () => {
    setIsActive(true)

    setTimeout(() => {
      recognitionRef.current?.start()
    }, 300)

    const welcome = "Hello. I am GMU VoiceBot. How can I help you?"
    setResponse(welcome)
    speak(welcome)
  }

  
  const closeAssistant = () => {
    setIsActive(false)
    recognitionRef.current?.stop()
    window.speechSynthesis.cancel()
  }

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>×</button>

          <h3>GMU VoiceBot</h3>

          <div className="voice-content">
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
