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
  const navigate = useNavigate()

  /* ================= LOCK FEMALE VOICE ================= */
  useEffect(() => {
    const loadVoices = () => {
      const voices = window.speechSynthesis.getVoices()
      if (!voices.length) return

      const female =
        voices.find(v => v.name.includes("Zira")) ||
        voices.find(v => v.name.includes("Samantha")) ||
        voices.find(v => v.name.toLowerCase().includes("female")) ||
        voices.find(v => v.name.toLowerCase().includes("google"))

      if (female) {
        femaleVoiceRef.current = female
        console.log("Locked female voice:", female.name)
      }
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
    utterance.rate = 0.9
    utterance.pitch = 1.15
    utterance.volume = 1

    if (femaleVoiceRef.current) {
      utterance.voice = femaleVoiceRef.current
    }

    window.speechSynthesis.speak(utterance)
  }

  /* ================= BACKEND CALL ================= */
  const askAI = async (text) => {
    try {
      const res = await fetch(
  "http://localhost/gmu-voice-assistant/backend/api.php",
  {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "include",   // 🔥 VERY IMPORTANT
    body: JSON.stringify({ message: text })
  }
)


      const data = await res.json()
      return data.reply || "No response from server"
    } catch (err) {
      console.error(err)
      return "Unable to connect to server."
    }
  }

  /* ================= COMMAND HANDLER ================= */
  const handleVoiceCommand = (command) => {
    if (!command) return

    console.log("Heard:", command)

    const cleaned = command.trim().toLowerCase()

    // Wake word
    if (cleaned.startsWith("hey gmu")) {
      const reply = "Yes, how can I help you?"
      setResponse(reply)
      speak(reply)
      return
    }

    // Navigation
    if (cleaned.includes("profile")) {
      speak("Opening profile")
      navigate("/profile")
      return
    }

    if (cleaned.includes("dashboard")) {
      speak("Opening dashboard")
      navigate("/dashboard")
      return
    }

    if (cleaned.includes("registration")) {
      speak("Opening registration")
      navigate("/registration")
      return
    }

    if (cleaned.includes("home")) {
      speak("Going home")
      navigate("/home")
      return
    }

    // ERP Query
    setResponse("Processing...")
    askAI(cleaned).then((reply) => {
      setResponse(reply)
      speak(reply)
    })
  }

  /* ================= SETUP RECOGNITION ================= */
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

    recognition.onresult = (event) => {
      const last = event.results.length - 1
      const command = event.results[last][0].transcript
      setTranscript(command)
      handleVoiceCommand(command)
    }

    recognition.onend = () => {
      if (isActive) {
        recognition.start()   // restart only when active
      }
    }

    recognitionRef.current = recognition
  }, [isActive])

  /* ================= ACTIVATE BUTTON ================= */
  const activateAssistant = () => {
    setIsActive(true)

    if (recognitionRef.current) {
      recognitionRef.current.start()
    }

    speak("Hello. I am GMU VoiceBot. Say Hey to activate me.")
  }

  /* ================= CLOSE BUTTON ================= */
  const closeAssistant = () => {
    setIsActive(false)
    recognitionRef.current?.stop()
  }

  return (
    <div className="voice-assistant-container">

      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={closeAssistant}>
            ×
          </button>

          <h3>GMU VoiceBot</h3>

          <div className="voice-content">
            <p><b>You:</b> {transcript}</p>
            <p><b>Assistant:</b> {response}</p>
            {errorMessage && (
              <p style={{ color: "red" }}>{errorMessage}</p>
            )}
          </div>
        </div>
      )}

      <button
        className="voice-assistant-btn"
        onClick={activateAssistant}
      >
        <img
          src={gmuLogo}
          alt="GMU VoiceBot"
          className="voice-logo"
        />
      </button>

    </div>
  )
}

export default VoiceAssistant
