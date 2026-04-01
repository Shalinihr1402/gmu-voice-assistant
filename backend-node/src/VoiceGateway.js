import crypto from "node:crypto";
import { sendJson } from "./utils/safeJson.js";
import { DeepgramLiveSttClient } from "./services/DeepgramLiveSttClient.js";
import { GeminiStreamClient } from "./services/GeminiStreamClient.js";
import { DeepgramTtsStreamClient } from "./services/DeepgramTtsStreamClient.js";
import { splitIntoSpeechChunks } from "./utils/chunkText.js";

export class VoiceGateway {
  constructor({ config, sessionManager }) {
    this.config = config;
    this.sessionManager = sessionManager;
    this.geminiClient = new GeminiStreamClient(config);
  }

  async initializeSession(ws, payload = {}) {
    const session = this.sessionManager.createOrGet(payload.sessionId, payload.userContext || {});
    ws.session = session;

    sendJson(ws, {
      type: "session.ready",
      sessionId: session.id
    });
  }

  async beginAudioStream(ws) {
    const session = ws.session;
    if (!session) {
      throw new Error("Session is not initialized.");
    }

    if (session.sttClient) {
      session.sttClient.close();
    }

    session.activeTurnId = crypto.randomUUID();
    sendJson(ws, { type: "turn.state", state: "listening", turnId: session.activeTurnId });

    session.sttClient = new DeepgramLiveSttClient(this.config, {
      onPartial: (text) => sendJson(ws, { type: "transcript.partial", text, turnId: session.activeTurnId }),
      onFinal: (text) => sendJson(ws, { type: "transcript.final", text, turnId: session.activeTurnId }),
      onSpeechFinal: () => sendJson(ws, { type: "turn.state", state: "transcribed", turnId: session.activeTurnId }),
      onClose: async (finalTranscript) => {
        if (!finalTranscript) {
          sendJson(ws, { type: "turn.state", state: "idle", turnId: session.activeTurnId });
          return;
        }

        await this.handleFinalTranscript(ws, finalTranscript);
      }
    });

    await session.sttClient.connect();
  }

  forwardAudioChunk(ws, audioBuffer) {
    ws.session?.sttClient?.sendAudio(audioBuffer);
  }

  endAudioStream(ws) {
    ws.session?.sttClient?.finalize();
  }

  async cancelResponse(ws) {
    const session = ws.session;
    if (!session) {
      return;
    }

    session.abortController?.abort();
    session.ttsClient?.clear();
    session.ttsClient?.close();
    session.sttClient?.close();
    this.sessionManager.clearTurnState(session);
    sendJson(ws, { type: "turn.state", state: "cancelled" });
  }

  async handleFinalTranscript(ws, transcript) {
    const session = ws.session;
    if (!session) {
      return;
    }

    sendJson(ws, { type: "turn.state", state: "thinking", turnId: session.activeTurnId });
    session.abortController = new AbortController();

    let latestPartial = "";
    const finalReply = await this.geminiClient.generate({
      text: transcript,
      session,
      signal: session.abortController.signal,
      onPartial: (text) => {
        latestPartial = text;
        sendJson(ws, { type: "llm.partial", text, turnId: session.activeTurnId });
      }
    });

    const replyText = finalReply || latestPartial || "I could not find an answer.";
    sendJson(ws, { type: "llm.final", text: replyText, turnId: session.activeTurnId });
    session.memory.addTurn(transcript, replyText);

    sendJson(ws, { type: "turn.state", state: "speaking", turnId: session.activeTurnId });
    await this.streamTts(ws, replyText);
    sendJson(ws, { type: "turn.state", state: "idle", turnId: session.activeTurnId });
    this.sessionManager.clearTurnState(session);
  }

  async streamTts(ws, text) {
    const session = ws.session;
    if (!session) {
      return;
    }

    if (session.ttsClient) {
      session.ttsClient.clear();
      session.ttsClient.close();
    }

    session.ttsClient = new DeepgramTtsStreamClient(this.config, {
      onAudio: (audioChunk) => {
        if (ws.readyState === ws.OPEN) {
          ws.send(audioChunk, { binary: true });
        }
      },
      onClose: () => sendJson(ws, { type: "tts.end", turnId: session.activeTurnId })
    });

    await session.ttsClient.connect();
    for (const chunk of splitIntoSpeechChunks(text)) {
      session.ttsClient.speakTextChunk(chunk);
    }
    session.ttsClient.close();
  }
}
