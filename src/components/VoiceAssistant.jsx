import { useState, useEffect, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import './VoiceAssistant.css'

const VoiceAssistant = () => {
  const [isListening, setIsListening] = useState(false)
  const [isActive, setIsActive] = useState(false)
  const [transcript, setTranscript] = useState('')
  const [response, setResponse] = useState('')
  const [errorMessage, setErrorMessage] = useState('')
  const recognitionRef = useRef(null)
  const navigate = useNavigate()

  // ✅ preload voices
  useEffect(() => {
    window.speechSynthesis.onvoiceschanged = () => {
      window.speechSynthesis.getVoices()
    }
  }, [])

  const speak = (text) => {
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel()
      const utterance = new SpeechSynthesisUtterance(text)
      utterance.lang = 'en-US'
      utterance.rate = 1
      utterance.pitch = 1.2

      const voices = window.speechSynthesis.getVoices()
      const femaleVoice = voices.find(v =>
        v.name.toLowerCase().includes('zira') ||
        v.name.toLowerCase().includes('female') ||
        v.name.toLowerCase().includes('woman') ||
        v.name.toLowerCase().includes('samantha')
      )

      if (femaleVoice) utterance.voice = femaleVoice
      window.speechSynthesis.speak(utterance)
    }
  }

const askAI = async (text) => {
  try {
    const res = await fetch("/api/gmu-voice-assistant/backend/api.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ message: text })
    })

    const raw = await res.text()
    console.log("RAW RESPONSE:", raw)

    if (!raw) return "Server returned empty response"

    const data = JSON.parse(raw)
    return data.reply || "No reply field in response"

  } catch (err) {
    console.error("FETCH ERROR:", err)
    return "Sorry, I am not able to connect to the server right now."
  }
}



  const handleVoiceCommand = (command) => {
    if (!command) return

    if (command.includes('hello') && command.includes('gmu')) {
      const reply = 'Hello! I am your GMU assistant. How can I help you?'
      setResponse(reply)
      speak(reply)

    } else if (command.includes('profile')) {
      speak("Opening profile")
      navigate('/profile')

    } else if (command.includes('dashboard')) {
      speak("Opening dashboard")
      navigate('/dashboard')

    } else if (command.includes('registration')) {
      speak("Opening registration")
      navigate('/registration')

    } else if (command.includes('home')) {
      speak("Going home")
      navigate('/')

    } else {
      setResponse("Thinking...")
      askAI(command).then(reply => {
        setResponse(reply)
        speak(reply)
      })
    }
  }

  // 🎤 speech recognition
  useEffect(() => {
    if (!('webkitSpeechRecognition' in window || 'SpeechRecognition' in window)) {
      setErrorMessage("Speech recognition not supported")
      return
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
    recognitionRef.current = new SpeechRecognition()
    recognitionRef.current.continuous = true
    recognitionRef.current.lang = 'en-US'

    recognitionRef.current.onresult = (event) => {
      const last = event.results.length - 1
      const command = event.results[last][0].transcript.toLowerCase()
      setTranscript(command)
      handleVoiceCommand(command)
    }

  }, [])

  const toggleListening = () => {
    if (isListening) {
      recognitionRef.current.stop()
      setIsListening(false)
    } else {
      recognitionRef.current.start()
      setIsListening(true)
    }
  }

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button className="close-btn" onClick={() => setIsActive(false)}>×</button>

          <h3>GMU Voice Assistant</h3>

          <button onClick={toggleListening}>
            {isListening ? "🛑 Stop Listening" : "🎤 Start Listening"}
          </button>

          <p><b>You said:</b> {transcript}</p>
          <p><b>Assistant:</b> {response}</p>
          {errorMessage && <p style={{color:"red"}}>{errorMessage}</p>}
        </div>
      )}

      <button className="voice-assistant-btn" onClick={() => {
        setIsActive(true)
        speak("Hello! I am your GMU assistant.")
      }}>
        🤖
      </button>
    </div>
  )
}

export default VoiceAssistant
