import { useEffect, useRef, useState } from "react"
import { useNavigate } from "react-router-dom"
import gmuLogo from "../assets/gmu-logo.png"
import "./VoiceAssistant.css"
import { fetchJson, getBackendUrl } from "../utils/api"

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
  const [voiceLanguage, setVoiceLanguage] = useState("en")

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

    if (shouldUseBrowserTts) {
      if (bufferedText) {
        speakWithBrowserFallback(bufferedText)
      }
      return
    }

    if (!bufferedText) {
      finishSpeaking()
      return
    }

    const playElevenLabsBlobAudio = async () => {
      const response = await fetch(getBackendUrl("elevenlabsTts.php"), {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
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
      return data.reply || localizedText.noAnswer
    } catch {
      setReplySource("request_failed")
      return localizedText.serverError
    }
  }

  const replyImmediately = (text) => {
    setResponse(text)
    setReplySource("")
    void speak(text, { preferBrowser: languageConfig.ttsProvider !== "elevenlabs" })
    lastCommandRef.current = ""
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

  const getPaymentSupportReply = async (text) => {
    const normalized = (text || "").trim().toLowerCase()
    const paymentIntent = /\b(payment|pay fees|pay my fees|fee payment|payment options|how can i pay|how to pay|receipt|grievance)\b/.test(normalized)
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

    return {
      type: "reply",
      reply: replyInSelectedLanguage(
        `You can pay your fees from the payment portal. Available options are College or Tuition Fee, Hostel Fee, Skill or Late Registration or Other Fee, Download Receipt, Payment Grievance, and Grievance Result. Your current pending balance is rupees ${formattedBalance}.`,
        `आप payment portal से अपनी fees भर सकते हैं। उपलब्ध options हैं College या Tuition Fee, Hostel Fee, Skill या Late Registration या Other Fee, Download Receipt, Payment Grievance, और Grievance Result। आपका current pending balance ${formattedBalance} रुपये है।`,
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
          "आपका फाइनल रजिस्ट्रेशन अभी पेंडिंग है, क्योंकि फीस बैलेंस बाकी है।"
        )
        : replyInSelectedLanguage(
          "Your final registration is completed successfully.",
          "आपका फाइनल रजिस्ट्रेशन सफलतापूर्वक पूरा हो चुका है।"
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
          `आपका अभी का pending fee balance ${formattedBalance} रुपये है।`
        )
        : replyInSelectedLanguage(
          "You do not have any pending fee balance.",
          "आपका कोई pending fee balance नहीं है।"
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
        setTimeout(() => navigate("/" + (isStudentUser ? "dashboard" : "portal")), 800)
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
        setTimeout(() => navigate("/" + target), 800)
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
        setTimeout(() => navigate("/" + target), 800)
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
      if (/ರಿಜಿಸ್ಟ್ರೇಶನ್|ನೋಂದಣಿ|rijistreshan/u.test(cleaned) && (asksToOpenPage || !asksStatusOnly)) {
        return isStudent ? "registration" : "portal"
      }
      if (hasAnyText([/\b(profile|profle)\b/, /प्रोफाइल/])) return "profile"
      if (hasAnyText([/\b(dashboard|dash board)\b/, /डैशबोर्ड/])) return "dashboard"
      if (hasAnyText([/\b(home|main page)\b/, /होम/])) return isStaff ? "portal" : "home"
      if (hasAnyText([/\b(portal|role portal)\b/, /पोर्टल/])) return "portal"
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
      const reply = await askAI(intentText)
      setResponse(reply)
      void speak(reply)
      lastCommandRef.current = ""
      return
    }

    setIsProcessing(true)
    setResponse(localizedText.thinking)

    const reply = await askAI(intentText)

    setIsProcessing(false)
    setResponse(reply)
    void speak(reply)
    lastCommandRef.current = ""
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
          ? `ನಮಸ್ಕಾರ ${firstName}. ನಾನು GMU VoiceBot ಮುಂದಿನ ಪ್ರಶ್ನೆಗೆ ಮುಂದುವರಿಯಲು, ದಯವಿಟ್ಟು GMU ವೃತ್ತಾಕಾರದ ಬಟನ್ ಅನ್ನು ಒತ್ತುವಂತೆ ವಿನಂತಿಸುತ್ತೇವೆ.`
          : "ನಮಸ್ಕಾರ. ನಾನು GMU VoiceBot ಮುಂದಿನ ಪ್ರಶ್ನೆಗೆ ಮುಂದುವರಿಯಲು, ದಯವಿಟ್ಟು GMU ವೃತ್ತಾಕಾರದ ಬಟನ್ ಅನ್ನು ಒತ್ತುವಂತೆ ವಿನಂತಿಸುತ್ತೇವೆ."
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
  const showTapHint = isActive && !isListening && !isProcessing && !isSpeaking

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
