import { useEffect, useRef, useState } from "react"
import { useNavigate } from "react-router-dom"
import gmuLogo from "../assets/gmu-logo.png"
import "./VoiceAssistant.css"
import { fetchJson, getBackendUrl } from "../utils/api"

const MAX_RECORDING_MS = 8000
const STREAMING_TIMESLICE_MS = 250
const DEEPGRAM_ENDPOINTING_MS = 300
const USE_BROWSER_TTS_BY_DEFAULT = false
const DEEPGRAM_TTS_MODEL = "aura-2-asteria-en"
const DEEPGRAM_TTS_SAMPLE_RATE = 24000
const INTERRUPT_SPEECH_THRESHOLD = 0.05
const INTERRUPT_MIN_FRAMES = 4
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
  const ignoreNextRecordingRef = useRef(false)
  const isSpeakingRef = useRef(false)
  const lastSpokenTextRef = useRef("")
  const isActiveRef = useRef(false)
  const isProcessingRef = useRef(false)
  const lastPageRef = useRef(null)
  const lastCommandRef = useRef("")
  const deepgramSocketRef = useRef(null)
  const finalTranscriptRef = useRef("")
  const interimTranscriptRef = useRef("")
  const transcriptSubmittedRef = useRef(false)
  const streamClosedRef = useRef(false)
  const ttsSocketRef = useRef(null)
  const ttsAudioContextRef = useRef(null)
  const ttsNextPlaybackTimeRef = useRef(0)
  const ttsSourcesRef = useRef(new Set())
  const ttsStreamGenerationRef = useRef(0)
  const interruptStreamRef = useRef(null)
  const interruptAudioContextRef = useRef(null)
  const interruptAnalyserRef = useRef(null)
  const interruptSourceNodeRef = useRef(null)
  const interruptAnimationRef = useRef(null)
  const interruptSpeechFramesRef = useRef(0)
  const interruptInProgressRef = useRef(false)

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

  const cleanupStreamingPlayback = async () => {
    ttsSourcesRef.current.forEach((source) => {
      try {
        source.stop()
      } catch {}
    })
    ttsSourcesRef.current.clear()
    ttsNextPlaybackTimeRef.current = 0

    if (ttsAudioContextRef.current) {
      const context = ttsAudioContextRef.current
      ttsAudioContextRef.current = null
      try {
        await context.close()
      } catch {}
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
      await stopInterruptMonitor()
      await stopStreamingTts({ clearRemote: true })
      cleanupAudio()
      window.speechSynthesis.cancel()
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
    ttsStreamGenerationRef.current += 1

    if (ttsSocketRef.current) {
      const socket = ttsSocketRef.current
      ttsSocketRef.current = null

      if (socket.readyState === WebSocket.OPEN && clearRemote) {
        socket.send(JSON.stringify({ type: "Clear" }))
        socket.send(JSON.stringify({ type: "Close" }))
      }

      try {
        socket.close()
      } catch {}
    }

    await cleanupStreamingPlayback()
    await stopInterruptMonitor()
    finishSpeaking()
  }

  const finishSpeaking = () => {
    isSpeakingRef.current = false
    setIsSpeaking(false)
    setStartupStatus("")
    void stopInterruptMonitor()
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

  const fetchDeepgramToken = async () => {
    const data = await fetchJson("deepgramToken.php")
    return data.token
  }

  const closeDeepgramSocket = () => {
    if (!deepgramSocketRef.current) {
      return
    }

    const socket = deepgramSocketRef.current
    deepgramSocketRef.current = null

    if (socket.readyState === WebSocket.OPEN) {
      socket.send(JSON.stringify({ type: "CloseStream" }))
    }

    socket.close()
  }

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

  const openDeepgramStream = async () => {
    const token = await fetchDeepgramToken()
    const query = new URLSearchParams({
      model: "nova-3",
      language: "en-US",
      punctuate: "true",
      smart_format: "true",
      interim_results: "true",
      endpointing: String(DEEPGRAM_ENDPOINTING_MS)
    })

    return new Promise((resolve, reject) => {
      const socket = new WebSocket(`wss://api.deepgram.com/v1/listen?${query.toString()}`, ["token", token])
      deepgramSocketRef.current = socket

      socket.onopen = () => resolve(socket)
      socket.onerror = () => reject(new Error("Unable to connect to Deepgram streaming STT."))
      socket.onclose = async () => {
        if (deepgramSocketRef.current === socket) {
          deepgramSocketRef.current = null
        }

        if (streamClosedRef.current) {
          return
        }

        streamClosedRef.current = true
        const finalText = getCombinedTranscript()

        if (finalText && isSelfTranscript(finalText)) {
          setTranscript(finalText.toLowerCase())
          setResponse("Tap the voice button when you are ready with your next question.")
          setReplySource("")
          lastCommandRef.current = ""
          return
        }

        if (finalText && !transcriptSubmittedRef.current) {
          await submitStreamingTranscript(finalText)
          return
        }

        if (!finalText && isActiveRef.current) {
          setTranscript("")
          setResponse("I did not catch that. Tap the voice button and try again.")
          setReplySource("")
          lastCommandRef.current = ""
        }
      }

      socket.onmessage = async (event) => {
        let payload = null

        try {
          payload = JSON.parse(event.data)
        } catch {
          return
        }

        if (payload.type !== "Results") {
          return
        }

        const nextText = (payload.channel?.alternatives?.[0]?.transcript || "").trim()
        if (!nextText) {
          return
        }

        if (payload.is_final) {
          appendFinalTranscript(nextText)
          interimTranscriptRef.current = ""
        } else {
          interimTranscriptRef.current = nextText
        }

        const liveTranscript = getCombinedTranscript()
        if (liveTranscript) {
          setTranscript(liveTranscript.toLowerCase())
        }

        if (payload.speech_final && mediaRecorderRef.current?.state !== "inactive") {
          mediaRecorderRef.current.stop()
        }
      }
    })
  }

  const ensureTtsAudioContext = async () => {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext

    if (!AudioContextClass) {
      throw new Error("Streaming audio playback is not supported in this browser.")
    }

    if (!ttsAudioContextRef.current || ttsAudioContextRef.current.state === "closed") {
      ttsAudioContextRef.current = new AudioContextClass({
        sampleRate: DEEPGRAM_TTS_SAMPLE_RATE
      })
      ttsNextPlaybackTimeRef.current = 0
    }

    if (ttsAudioContextRef.current.state === "suspended") {
      await ttsAudioContextRef.current.resume()
    }

    return ttsAudioContextRef.current
  }

  const scheduleLinear16Chunk = async (chunkBuffer, generation) => {
    if (generation !== ttsStreamGenerationRef.current) {
      return
    }

    const context = await ensureTtsAudioContext()
    const pcm = new Int16Array(chunkBuffer)
    if (!pcm.length) {
      return
    }

    const samples = new Float32Array(pcm.length)
    for (let index = 0; index < pcm.length; index += 1) {
      samples[index] = pcm[index] / 32768
    }

    const audioBuffer = context.createBuffer(1, samples.length, DEEPGRAM_TTS_SAMPLE_RATE)
    audioBuffer.copyToChannel(samples, 0)

    const source = context.createBufferSource()
    source.buffer = audioBuffer
    source.connect(context.destination)

    const startAt = Math.max(context.currentTime + 0.04, ttsNextPlaybackTimeRef.current)
    ttsNextPlaybackTimeRef.current = startAt + audioBuffer.duration

    ttsSourcesRef.current.add(source)
    source.onended = () => {
      ttsSourcesRef.current.delete(source)
      if (!ttsSourcesRef.current.size && !ttsSocketRef.current) {
        finishSpeaking()
      }
    }

    if (!isSpeakingRef.current) {
      isSpeakingRef.current = true
      setIsSpeaking(true)
      void startInterruptMonitor()
    }

    source.start(startAt)
  }

  const chunkTextForTts = (text) => {
    const normalized = (text || "").replace(/\s+/g, " ").trim()
    if (!normalized) {
      return []
    }

    const matches = normalized.match(/[^.!?]+[.!?]+|[^.!?]+$/g) || [normalized]
    return matches.map((part) => part.trim()).filter(Boolean)
  }

  async function* sentenceChunkStream(textOrStream) {
    if (typeof textOrStream === "string") {
      for (const sentence of chunkTextForTts(textOrStream)) {
        yield sentence
      }
      return
    }

    if (!textOrStream || typeof textOrStream[Symbol.asyncIterator] !== "function") {
      return
    }

    let buffer = ""
    for await (const chunk of textOrStream) {
      buffer += chunk || ""

      const sentences = chunkTextForTts(buffer)
      const endsWithBoundary = /[.!?]["')\]]?\s*$/.test(buffer)
      const completeCount = endsWithBoundary ? sentences.length : Math.max(sentences.length - 1, 0)

      for (let index = 0; index < completeCount; index += 1) {
        yield sentences[index]
      }

      buffer = endsWithBoundary ? "" : (sentences.at(-1) || "")
    }

    const trailing = buffer.trim()
    if (trailing) {
      yield trailing
    }
  }

  const openDeepgramTtsStream = async () => {
    const token = await fetchDeepgramToken()
    const params = new URLSearchParams({
      model: DEEPGRAM_TTS_MODEL,
      encoding: "linear16",
      sample_rate: String(DEEPGRAM_TTS_SAMPLE_RATE)
    })

    return new Promise((resolve, reject) => {
      const socket = new WebSocket(`wss://api.deepgram.com/v1/speak?${params.toString()}`, ["token", token])
      const generation = ttsStreamGenerationRef.current
      ttsSocketRef.current = socket

      socket.binaryType = "arraybuffer"
      socket.onopen = () => resolve({ socket, generation })
      socket.onerror = () => reject(new Error("Unable to connect to Deepgram streaming TTS."))
      socket.onclose = () => {
        if (ttsSocketRef.current === socket) {
          ttsSocketRef.current = null
        }

        if (!ttsSourcesRef.current.size) {
          finishSpeaking()
        }
      }
      socket.onmessage = async (event) => {
        if (typeof event.data === "string") {
          return
        }

        const chunkBuffer = event.data instanceof ArrayBuffer
          ? event.data
          : await event.data.arrayBuffer()

        await scheduleLinear16Chunk(chunkBuffer, generation)
      }
    })
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

    cleanupRecorder({ ignoreTranscript: true })
    cleanupAudio()
    window.speechSynthesis.cancel()
    await stopStreamingTts({ clearRemote: true })
    const generation = ttsStreamGenerationRef.current + 1
    ttsStreamGenerationRef.current = generation

    try {
      lastSpokenTextRef.current = typeof textOrStream === "string" ? textOrStream : ""
      const { socket } = await openDeepgramTtsStream()

      for await (const sentence of sentenceChunkStream(textOrStream)) {
        if (!sentence || ttsStreamGenerationRef.current !== generation) {
          continue
        }

        socket.send(JSON.stringify({
          type: "Speak",
          text: sentence
        }))

        socket.send(JSON.stringify({ type: "Flush" }))
      }

      if (ttsStreamGenerationRef.current === generation && socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify({ type: "Close" }))
      }
    } catch {
      await stopStreamingTts({ clearRemote: false })
      const bufferedText = typeof textOrStream === "string" ? textOrStream : ""
      if (bufferedText) {
        speakWithBrowserFallback(bufferedText)
      } else {
        finishSpeaking()
      }
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
      setErrorMessage("Microphone recording is not supported in this browser.")
      return
    }

    try {
      resetStreamingTranscript()
      await openDeepgramStream()

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

        if (!event.data.size || !deepgramSocketRef.current || deepgramSocketRef.current.readyState !== WebSocket.OPEN) {
          return
        }

        event.data.arrayBuffer().then((buffer) => {
          if (deepgramSocketRef.current?.readyState === WebSocket.OPEN) {
            deepgramSocketRef.current.send(buffer)
          }
        }).catch(() => {})
      }

      recorder.onstop = async () => {
        cleanupRecorder()

        if (ignoreNextRecordingRef.current) {
          ignoreNextRecordingRef.current = false
          closeDeepgramSocket()
          return
        }

        if (!chunks.length || !isActiveRef.current) {
          closeDeepgramSocket()
          return
        }

        if (deepgramSocketRef.current?.readyState === WebSocket.OPEN) {
          deepgramSocketRef.current.send(JSON.stringify({ type: "Finalize" }))
          window.setTimeout(() => {
            if (deepgramSocketRef.current?.readyState === WebSocket.OPEN) {
              deepgramSocketRef.current.send(JSON.stringify({ type: "CloseStream" }))
            }
          }, 150)
        } else {
          const finalText = getCombinedTranscript()
          if (finalText && !isSelfTranscript(finalText)) {
            await submitStreamingTranscript(finalText)
          }
        }
      }

      streamRef.current = stream
      mediaRecorderRef.current = recorder
      setErrorMessage("")
      setIsListening(true)
      recorder.start(STREAMING_TIMESLICE_MS)

      listenTimeoutRef.current = setTimeout(() => {
        if (recorder.state !== "inactive") {
          recorder.stop()
        }
      }, MAX_RECORDING_MS)
    } catch (error) {
      closeDeepgramSocket()
      cleanupRecorder()
      setErrorMessage(error.message || "Unable to start streaming transcription.")
    }
  }

  const speakWithBrowserFallback = (text) => {
    if (!("speechSynthesis" in window)) {
      finishSpeaking()
      return
    }

    void stopStreamingTts({ clearRemote: true })
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
      void startInterruptMonitor()
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

    if (/\b(who am i|do you know who i am|my profile|tell me about my profile|what am i studying)\b/.test(text)) {
      if (fullName && semesterLabel && branch) {
        return `You are ${fullName}, a ${semesterLabel} semester ${branch} student at GM University. How can I help you today?`
      }

      if (fullName && branch) {
        return `You are ${fullName} from the ${branch} department at GM University. How can I help you today?`
      }
    }

    return ""
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

    const localProfileReply = getLocalProfileReply(cleaned)
    if (localProfileReply) {
      setReplySource("local_profile")
      replyImmediately(localProfileReply)
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
      closeDeepgramSocket()
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
    setStartupStatus("Starting assistant...")

    const roleLabel = currentUser?.role_name ? ` for ${currentUser.role_name}` : ""
    const welcome = `Hello${currentUser?.full_name ? ` ${currentUser.full_name}` : ""}. I am GMU VoiceBot${roleLabel}, your voice assistant for profile, fees, attendance, results, and course support. How can I help you today?`
    setResponse(welcome)
    setTranscript("")
    setReplySource("")
    void speak(welcome)
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
      await stopStreamingTts({ clearRemote: true })
      cleanupAudio()
      window.speechSynthesis.cancel()
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
    closeDeepgramSocket()
    void stopInterruptMonitor()
    void stopStreamingTts({ clearRemote: false })
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
