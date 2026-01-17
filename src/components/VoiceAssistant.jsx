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
  const timeoutRef = useRef(null)

  const speak = (text) => {
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel()
      const utterance = new SpeechSynthesisUtterance(text)
      utterance.lang = 'en-US'
      utterance.rate = 1
      utterance.pitch = 1
      window.speechSynthesis.speak(utterance)
    }
  }

  const handleVoiceCommand = (command) => {
    console.log('Command received:', command)
    let reply = ''
    
    if (!command || command.trim().length === 0) {
      setErrorMessage('No voice input detected. Please speak clearly.')
      return
    }
    
    if (command.includes('hello') && (command.includes('gmu') || command.includes('g m u'))) {
      reply = 'Hello! How can I help you today?'
      setErrorMessage('')
      setResponse(reply)
      speak(reply)
    } else if (command.includes('show my profile') || command.includes('show profile') || command.includes('my profile')) {
      reply = 'Taking you to your profile page'
      setErrorMessage('')
      setResponse(reply)
      speak(reply)
      setTimeout(() => navigate('/profile'), 1000)
    } else if (command.includes('go to profile') || command.includes('open profile')) {
      reply = 'Navigating to profile'
      setErrorMessage('')
      setResponse(reply)
      speak(reply)
      setTimeout(() => navigate('/profile'), 1000)
    } else if (command.includes('dashboard') || command.includes('student dashboard')) {
      reply = 'Opening dashboard'
      setErrorMessage('')
      setResponse(reply)
      speak(reply)
      setTimeout(() => navigate('/dashboard'), 1000)
    } else if (command.includes('registration') || command.includes('course registration')) {
      reply = 'Opening registration page'
      setErrorMessage('')
      setResponse(reply)
      speak(reply)
      setTimeout(() => navigate('/registration'), 1000)
    } else if (command.includes('home') || command.includes('home page')) {
      reply = 'Going to home page'
      setErrorMessage('')
      setResponse(reply)
      speak(reply)
      setTimeout(() => navigate('/'), 1000)
    } else {
      setErrorMessage('Sorry, I did not understand: "' + command + '". Please try: "Hello GMU", "Show my profile", "Go to dashboard", "Open registration", or "Go to home".')
      setResponse('Command not recognized. Please try again.')
      speak('Command not recognized. Please try again.')
    }
  }

  useEffect(() => {
    // Initialize Speech Recognition only once
    if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
      
      if (!recognitionRef.current) {
        recognitionRef.current = new SpeechRecognition()
        recognitionRef.current.continuous = true
        recognitionRef.current.interimResults = false
        recognitionRef.current.lang = 'en-US'

        recognitionRef.current.onresult = (event) => {
          console.log('Speech result received')
          // Clear timeout when we get a result
          if (timeoutRef.current) {
            clearTimeout(timeoutRef.current)
            timeoutRef.current = null
          }
          
          const lastResultIndex = event.results.length - 1
          if (event.results[lastResultIndex] && event.results[lastResultIndex][0]) {
            const command = event.results[lastResultIndex][0].transcript.toLowerCase().trim()
            console.log('Transcript:', command)
            setTranscript(command)
            handleVoiceCommand(command)
          }
        }

        recognitionRef.current.onstart = () => {
          console.log('Speech recognition started')
          setErrorMessage('')
          setResponse('Listening... Speak your command')
        }

        recognitionRef.current.onend = () => {
          console.log('Speech recognition ended')
          // Only restart if user is still in listening mode
          if (isListening) {
            setTimeout(() => {
              if (isListening && recognitionRef.current) {
                try {
                  recognitionRef.current.start()
                } catch (e) {
                  console.log('Recognition restart error (normal):', e)
                }
              }
            }, 100)
          }
        }

        recognitionRef.current.onerror = (event) => {
          console.log('Speech recognition error:', event.error)
          
          if (event.error === 'network') {
            // Ignore network errors
            return
          }
          
          if (event.error === 'no-speech') {
            // No speech detected - this is normal
            return
          }
          
          if (event.error === 'audio-capture') {
            setIsListening(false)
            setErrorMessage('Microphone not found. Please check your microphone.')
            setResponse('Microphone error')
          } else if (event.error === 'not-allowed') {
            setIsListening(false)
            setErrorMessage('Microphone access denied. Please allow microphone access.')
            setResponse('Permission denied')
          } else if (event.error === 'aborted') {
            // Normal when stopping
            return
          }
        }
      }
    } else {
      console.warn('Speech recognition not supported in this browser')
      setErrorMessage('Speech recognition not supported. Please use Chrome or Edge browser.')
    }

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }
    }
  }, [])

  // Update isListening in recognition handlers
  useEffect(() => {
    if (recognitionRef.current) {
      const recognition = recognitionRef.current
      
      const originalOnEnd = recognition.onend
      recognition.onend = () => {
        if (originalOnEnd) originalOnEnd()
        if (isListening) {
          setTimeout(() => {
            if (isListening && recognitionRef.current) {
              try {
                recognitionRef.current.start()
              } catch (e) {
                // Ignore
              }
            }
          }, 100)
        }
      }
    }
  }, [isListening])

  const toggleListening = () => {
    if (isListening) {
      // Stop listening
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
        timeoutRef.current = null
      }
      if (recognitionRef.current) {
        recognitionRef.current.stop()
      }
      setIsListening(false)
      setResponse('Stopped listening')
      setErrorMessage('')
      setTimeout(() => setResponse(''), 2000)
    } else {
      // Start listening
      setIsActive(true)
      setResponse('Listening... Please speak now')
      setErrorMessage('')
      setIsListening(true)
      
      // Set timeout - 5 seconds to get response
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
      }
      timeoutRef.current = setTimeout(() => {
        if (isListening) {
          if (recognitionRef.current) {
            recognitionRef.current.stop()
          }
          setIsListening(false)
          setResponse('No response received. Please try again.')
          setErrorMessage('No voice input detected in 5 seconds. Please speak clearly and try again.')
        }
      }, 5000)
      
      // Start recognition
      setTimeout(() => {
        if (recognitionRef.current && isListening) {
          try {
            recognitionRef.current.start()
          } catch (e) {
            console.error('Error starting recognition:', e)
            setErrorMessage('Error starting microphone. Please check permissions.')
            setResponse('Microphone error')
            setIsListening(false)
            if (timeoutRef.current) {
              clearTimeout(timeoutRef.current)
              timeoutRef.current = null
            }
          }
        }
      }, 100)
    }
  }

  const handleClick = () => {
    setIsActive(!isActive)
    if (!isActive) {
      speak('Hello! I am your GMU assistant. How can I help you?')
      setResponse('Hello! How can I help you?')
    } else {
      if (isListening && recognitionRef.current) {
        recognitionRef.current.stop()
        setIsListening(false)
      }
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current)
        timeoutRef.current = null
      }
      setErrorMessage('')
    }
  }

  return (
    <div className="voice-assistant-container">
      {isActive && (
        <div className="voice-assistant-panel">
          <button 
            className="close-btn"
            onClick={() => {
              setIsActive(false)
              if (isListening && recognitionRef.current) {
                recognitionRef.current.stop()
                setIsListening(false)
              }
              if (timeoutRef.current) {
                clearTimeout(timeoutRef.current)
                timeoutRef.current = null
              }
              setErrorMessage('')
            }}
          >
            ×
          </button>
          <div className="assistant-content">
            <h3>GMU Voice Assistant</h3>
            <button
              className={`listen-btn ${isListening ? 'listening' : ''}`}
              onClick={toggleListening}
            >
              {isListening ? '🛑 Stop Listening' : '🎤 Start Listening'}
            </button>
            {transcript && (
              <div className="transcript">
                <strong>You said:</strong> {transcript}
              </div>
            )}
            {response && (
              <div className="response">
                <strong>Assistant:</strong> {response}
              </div>
            )}
            {errorMessage && (
              <div className="error-message">
                <strong>⚠️</strong> {errorMessage}
              </div>
            )}
            <div className="commands-hint">
              <p><strong>Try saying:</strong></p>
              <ul>
                <li>"Hello GMU"</li>
                <li>"Show my profile"</li>
                <li>"Go to dashboard"</li>
                <li>"Open registration"</li>
                <li>"Go to home"</li>
              </ul>
              {isListening && (
                <p className="listening-status">🎤 Listening... Speak your command</p>
              )}
            </div>
          </div>
        </div>
      )}
      <button
        className="voice-assistant-btn"
        onClick={handleClick}
        title="Click to activate voice assistant"
      >
        🤖
      </button>
    </div>
  )
}

export default VoiceAssistant
