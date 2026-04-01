import WebSocket from "ws";

export class DeepgramLiveSttClient {
  constructor(config, handlers = {}) {
    this.config = config;
    this.handlers = handlers;
    this.socket = null;
    this.finalTranscript = "";
    this.interimTranscript = "";
  }

  connect() {
    return new Promise((resolve, reject) => {
      const params = new URLSearchParams({
        model: this.config.deepgramSttModel,
        interim_results: "true",
        punctuate: "true",
        smart_format: "true",
        endpointing: "300"
      });

      this.socket = new WebSocket(`wss://api.deepgram.com/v1/listen?${params.toString()}`, {
        headers: {
          Authorization: `Token ${this.config.deepgramApiKey}`
        }
      });

      this.socket.on("open", resolve);
      this.socket.on("error", reject);
      this.socket.on("message", (data) => this.handleMessage(data));
      this.socket.on("close", () => {
        this.handlers.onClose?.(this.getTranscript());
      });
    });
  }

  sendAudio(buffer) {
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.socket.send(buffer);
    }
  }

  finalize() {
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify({ type: "Finalize" }));
      setTimeout(() => {
        if (this.socket?.readyState === WebSocket.OPEN) {
          this.socket.send(JSON.stringify({ type: "CloseStream" }));
        }
      }, 150);
    }
  }

  close() {
    if (this.socket) {
      try {
        this.socket.close();
      } catch {}
      this.socket = null;
    }
  }

  getTranscript() {
    return `${this.finalTranscript} ${this.interimTranscript}`.replace(/\s+/g, " ").trim();
  }

  handleMessage(data) {
    const payload = JSON.parse(String(data));
    if (payload.type !== "Results") {
      return;
    }

    const nextText = String(payload.channel?.alternatives?.[0]?.transcript || "").trim();
    if (!nextText) {
      return;
    }

    if (payload.is_final) {
      if (!this.finalTranscript.includes(nextText)) {
        this.finalTranscript = `${this.finalTranscript} ${nextText}`.replace(/\s+/g, " ").trim();
      }
      this.interimTranscript = "";
      this.handlers.onFinal?.(this.finalTranscript);
    } else {
      this.interimTranscript = nextText;
      this.handlers.onPartial?.(this.getTranscript());
    }

    if (payload.speech_final) {
      this.handlers.onSpeechFinal?.(this.getTranscript());
    }
  }
}
