const API_URL = "backend/api.php";
const LIBRE_TRANSLATE_URL = "https://libretranslate.de/translate";

const languageSelect = document.getElementById("languageSelect");
const recordButton = document.getElementById("recordButton");
const sendTextButton = document.getElementById("sendTextButton");
const stopAudioButton = document.getElementById("stopAudioButton");
const typedText = document.getElementById("typedText");
const statusBox = document.getElementById("status");
const transcriptBox = document.getElementById("transcript");
const englishTextBox = document.getElementById("englishText");
const backendReplyBox = document.getElementById("backendReply");
const translatedReplyBox = document.getElementById("translatedReply");
const replyAudio = document.getElementById("replyAudio");

let mediaRecorder = null;
let recordedChunks = [];
let activeAudioUrl = null;

function getSupportedAudioMimeType() {
  const candidates = [
    "audio/webm;codecs=opus",
    "audio/webm",
    "audio/mp4",
  ];

  return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || "";
}

function setStatus(message, type = "") {
  statusBox.textContent = message;
  statusBox.className = `status ${type}`.trim();
}

function setBusy(isBusy) {
  recordButton.disabled = isBusy && !mediaRecorder;
  sendTextButton.disabled = isBusy;
}

function resetOutput() {
  transcriptBox.textContent = "";
  englishTextBox.textContent = "";
  backendReplyBox.textContent = "";
  translatedReplyBox.textContent = "";
}

async function postJson(url, body) {
  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  const data = await response.json().catch(() => null);

  if (!response.ok || !data || data.status === "error") {
    throw new Error(data?.message || data?.reply || `Request failed with HTTP ${response.status}`);
  }

  return data;
}

async function translateText(text, source, target) {
  if (!text.trim() || source === target) {
    return text;
  }

  const response = await fetch(LIBRE_TRANSLATE_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      q: text,
      source,
      target,
      format: "text",
    }),
  });

  const data = await response.json().catch(() => null);

  if (!response.ok || !data?.translatedText) {
    throw new Error("Translation failed. Check the LibreTranslate endpoint or run your own instance.");
  }

  return data.translatedText;
}

async function transcribeAudio(audioBlob, language) {
  const formData = new FormData();
  formData.append("audio", audioBlob, "speech.webm");
  formData.append("language", language);

  const response = await fetch(`${API_URL}?action=transcribe`, {
    method: "POST",
    body: formData,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok || !data || data.status === "error") {
    throw new Error(data?.message || "Deepgram transcription failed.");
  }

  return data.transcript || "";
}

async function getBackendReply(englishText, selectedLanguage) {
  const data = await postJson(`${API_URL}?action=chat`, {
    message: englishText,
    language: selectedLanguage,
  });

  return data.reply || "";
}

async function speakReply(text, language) {
  stopAudio();

  const response = await fetch(`${API_URL}?action=tts`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      text,
      language,
    }),
  });

  if (!response.ok) {
    throw new Error("ElevenLabs TTS failed.");
  }

  const audioBlob = await response.blob();

  if (!audioBlob.size || !audioBlob.type.includes("audio")) {
    throw new Error("TTS response did not contain playable audio.");
  }

  activeAudioUrl = URL.createObjectURL(audioBlob);
  replyAudio.src = activeAudioUrl;
  await replyAudio.play();
}

function speakWithBrowser(text, language) {
  if (!window.speechSynthesis) {
    throw new Error("Browser speech synthesis is not available.");
  }

  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  utterance.lang = language === "hi" ? "hi-IN" : language === "kn" ? "kn-IN" : "en-US";
  window.speechSynthesis.speak(utterance);
}

function stopAudio() {
  replyAudio.pause();
  replyAudio.removeAttribute("src");
  replyAudio.load();

  if (activeAudioUrl) {
    URL.revokeObjectURL(activeAudioUrl);
    activeAudioUrl = null;
  }

  if (window.speechSynthesis) {
    window.speechSynthesis.cancel();
  }
}

async function runAssistant(userText, sourceLanguage) {
  const cleanText = userText.trim();

  if (!cleanText) {
    setStatus("No speech or text was detected. Please try again.", "error");
    return;
  }

  resetOutput();
  transcriptBox.textContent = cleanText;
  setBusy(true);

  try {
    setStatus("Translating request to English...");
    const englishText = await translateText(cleanText, sourceLanguage, "en");
    englishTextBox.textContent = englishText;

    setStatus("Sending request to ERP backend...");
    const backendReply = await getBackendReply(englishText, sourceLanguage);
    backendReplyBox.textContent = backendReply;

    setStatus("Translating backend response...");
    const localizedReply = await translateText(backendReply, "en", sourceLanguage);
    translatedReplyBox.textContent = localizedReply;

    setStatus("Creating voice response with ElevenLabs...");

    try {
      await speakReply(localizedReply, sourceLanguage);
      setStatus("Done. Audio is playing.", "success");
    } catch (ttsError) {
      if (sourceLanguage === "en") {
        throw ttsError;
      }

      setStatus("ElevenLabs failed, using browser speech fallback.", "error");
      speakWithBrowser(localizedReply, sourceLanguage);
    }
  } catch (error) {
    setStatus(error.message, "error");
  } finally {
    setBusy(false);
  }
}

async function startRecording() {
  if (!navigator.mediaDevices?.getUserMedia) {
    setStatus("Microphone recording is not supported in this browser.", "error");
    return;
  }

  try {
    stopAudio();
    recordedChunks = [];
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    const mimeType = getSupportedAudioMimeType();
    mediaRecorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);

    mediaRecorder.addEventListener("dataavailable", (event) => {
      if (event.data.size > 0) {
        recordedChunks.push(event.data);
      }
    });

    mediaRecorder.addEventListener("stop", async () => {
      stream.getTracks().forEach((track) => track.stop());
      recordButton.textContent = "Start recording";
      recordButton.classList.remove("recording");

      const selectedLanguage = languageSelect.value;
      const audioBlob = new Blob(recordedChunks, { type: mimeType || "audio/webm" });

      try {
        setBusy(true);
        setStatus("Sending audio to Deepgram...");
        const transcript = await transcribeAudio(audioBlob, selectedLanguage);
        await runAssistant(transcript, selectedLanguage);
      } catch (error) {
        setStatus(error.message, "error");
      } finally {
        mediaRecorder = null;
        setBusy(false);
      }
    });

    mediaRecorder.start();
    recordButton.textContent = "Stop recording";
    recordButton.classList.add("recording");
    setStatus("Recording...");
  } catch (error) {
    setStatus(error.message || "Could not access microphone.", "error");
  }
}

recordButton.addEventListener("click", () => {
  if (mediaRecorder && mediaRecorder.state === "recording") {
    mediaRecorder.stop();
    return;
  }

  startRecording();
});

sendTextButton.addEventListener("click", () => {
  runAssistant(typedText.value, languageSelect.value);
});

stopAudioButton.addEventListener("click", () => {
  stopAudio();
  setStatus("Audio stopped.");
});
