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
      setResponse("Listening for your question...")
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

    if (preferBrowser) {
      const bufferedText = typeof textOrStream === "string" ? textOrStream : ""
      if (bufferedText) {
        speakWithBrowserFallback(bufferedText)
      }
      return
    }

    const bufferedText = typeof textOrStream === "string" ? textOrStream : ""
    if (bufferedText) {
      speakWithBrowserFallback(bufferedText)
    } else {
      finishSpeaking()
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
      if (!SpeechRecognitionClass) {
        setErrorMessage("Microphone recording is not supported in this browser.")
        return
      }
    }

    try {
      resetStreamingTranscript()
      const SpeechRecognitionClass = getSpeechRecognitionClass()

      if (SpeechRecognitionClass) {
        const recognition = new SpeechRecognitionClass()
        recognitionRef.current = recognition
        recognitionTranscriptRef.current = ""
        recognitionFinalizedRef.current = false

        recognition.lang = "en-US"
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
            setResponse("I did not catch that. Tap the voice button and try again.")
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          setResponse("Processing your question...")
          setReplySource("")
          setIsProcessing(true)

          if (isSelfTranscript(finalText)) {
            setIsProcessing(false)
            setTranscript(finalText.toLowerCase())
            setResponse("Tap the voice button when you are ready with your next question.")
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

        setResponse("Processing your question...")
        setReplySource("")
        setIsProcessing(true)

        const audioBlob = new Blob(chunks, {
          type: mimeType || "audio/webm"
        })

        if (audioBlob.size < 2048) {
          setIsProcessing(false)
          setTranscript("")
          setResponse("I did not catch that. Tap the voice button and try again.")
          setReplySource("")
          lastCommandRef.current = ""
          return
        }

        const formData = new FormData()
        formData.append("audio", audioBlob, "voice-input.webm")

        try {
          const data = await fetchJson("deepgramTranscribe.php", {
            method: "POST",
            body: formData
          })

          const finalText = (data?.transcript || "").trim()
          if (!finalText) {
            setIsProcessing(false)
            setTranscript("")
            setResponse("I did not catch that. Tap the voice button and try again.")
            setReplySource("")
            lastCommandRef.current = ""
            return
          }

          if (isSelfTranscript(finalText)) {
            setIsProcessing(false)
            setTranscript(finalText.toLowerCase())
            setResponse("Tap the voice button when you are ready with your next question.")
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
      setErrorMessage(error.message || "Unable to start streaming transcription.")
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
    const { preferBrowser = USE_BROWSER_TTS_BY_DEFAULT } = options

    if (!text) return

    await speakTextStream(text, { preferBrowser })
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
    if (currentUser?.role_key !== "student") {
      return ""
    }

    const fullName = currentUser?.full_name || ""
    const branch = currentUser?.branch_name || currentUser?.unit_name || ""
    const semesterLabel = getOrdinalLabel(currentUser?.semester)

    if (/\b(which|what)\s+semester\b|\bmy semester\b/.test(text)) {
      return semesterLabel
        ? `You are currently in the ${semesterLabel} semester.`
        : ""
    }

    if (/\b(which|what)\s+(department|branch)\b|\bmy department\b|\bmy branch\b/.test(text)) {
      return branch
        ? `You are from the ${branch} department.`
        : ""
    }

    if (/\b(who am i|do you know who i am|do you know about me|know about me|tell me about me|my profile|tell me about my profile|what am i studying)\b/.test(text)) {
      if (fullName && semesterLabel && branch) {
        return `You are ${fullName}, a ${semesterLabel} semester ${branch} student at GM University. How can I help you today?`
      }

      if (fullName && branch) {
        return `You are ${fullName} from the ${branch} department at GM University. How can I help you today?`
      }
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

  const getFastDatabaseReply = async (text) => {
    const normalized = text.toLowerCase()

    if (/\b(final registration|registration status|registered or not|am i registered|have i registered)\b/.test(normalized)) {
      const payments = await loadPaymentCache()
      const totalBalance = Array.isArray(payments)
        ? payments.reduce((sum, item) => sum + Number(item.balance || 0), 0)
        : 0

      return totalBalance > 0
        ? "Your final registration is still pending because there is an outstanding fee balance."
        : "Your final registration is completed successfully."
    }

    if (/\b(fee balance|fees balance|pending fees|due fees|amount due|fee due|how much fee)\b/.test(normalized)) {
      const payments = await loadPaymentCache()
      const totalBalance = Array.isArray(payments)
        ? payments.reduce((sum, item) => sum + Number(item.balance || 0), 0)
        : 0
      const formattedBalance = formatCurrency(totalBalance)

      return totalBalance > 0
        ? `Your current pending fee balance is rupees ${formattedBalance}.`
        : "You do not have any pending fee balance."
    }

    if (/\b(my courses|my subjects|what subjects do i have|what courses do i have|course details|subject details)\b/.test(normalized)) {
      const courses = await loadCoursesCache()
      if (!Array.isArray(courses) || !courses.length) {
        return "I could not find your registered course details right now."
      }

      const topCourses = courses.slice(0, 3).map((course) => course.title).filter(Boolean)
      if (!topCourses.length) {
        return `You currently have ${courses.length} registered courses.`
      }

      return `You currently have ${courses.length} registered courses. Some of them are ${topCourses.join(", ")}.`
    }

    if (/\b(usn|registration number|university number)\b/.test(normalized)) {
      const profile = await loadProfileCache()
      if (profile?.usn) {
        return `Your USN is ${profile.usn}.`
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
      .replace(/\bassistant\b/g, "")
      .replace(/\b(can you|could you|please)\b/g, "")
      .trim()

    if (!cleaned) {
      setIsProcessing(false)
      replyImmediately("Hello. What can I help you with?")
      return
    }

    const localProfileReply = getLocalProfileReply(cleaned)
    if (localProfileReply) {
      setIsProcessing(false)
      setReplySource("local_profile")
      replyImmediately(localProfileReply)
      return
    }

    const fastDatabaseReply = await getFastDatabaseReply(cleaned)
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
        setIsProcessing(false)
        goToPage(lastPageRef.current, "Opening " + lastPageRef.current)
      } else {
        setIsProcessing(false)
        replyImmediately("Please tell me which page to open.")
      }
      return
    }

    if (cleaned.match(/\bprofile\b/) && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("profile", "Opening your profile page.")
      } else {
        goToPage("portal", "Opening your role portal.")
      }
      return
    }

    if (cleaned.match(/\bdashboard\b/) && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("dashboard", "Opening your dashboard.")
      } else {
        goToPage("portal", "Opening your role dashboard.")
      }
      return
    }

    if (cleaned.match(/\bregistration\b/) && !isRegistrationStatusQuery && isNavigationRequest) {
      setIsProcessing(false)
      if (isStudent) {
        goToPage("registration", "Opening your registration page.")
      } else {
        const staffReply = "Registration is currently a student-only page. Opening your role portal instead."
        goToPage("portal", staffReply)
      }
      return
    }

    if (cleaned.match(/\bhome\b/) && isNavigationRequest) {
      setIsProcessing(false)
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
    setStartupStatus("Tap to ask")

    const firstName = (currentUser?.full_name || "").trim().split(/\s+/)[0] || ""
    const welcome = firstName
      ? `Hello ${firstName}. I am GMU VoiceBot. Tap again and ask your question.`
      : "Hello. I am GMU VoiceBot. Tap again and ask your question."
    setResponse(welcome)
    setTranscript("")
    setReplySource("")
    void speak(welcome, { preferBrowser: true })
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

    setResponse("Listening for your question...")
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
