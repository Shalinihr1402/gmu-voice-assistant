import WebSocket from "ws";

export class DeepgramTtsStreamClient {
  constructor(config, handlers = {}) {
    this.config = config;
    this.handlers = handlers;
    this.socket = null;
  }

  connect() {
    return new Promise((resolve, reject) => {
      const params = new URLSearchParams({
        model: this.config.deepgramTtsModel,
        encoding: "linear16",
        sample_rate: String(this.config.deepgramTtsSampleRate)
      });

      this.socket = new WebSocket(`wss://api.deepgram.com/v1/speak?${params.toString()}`, {
        headers: {
          Authorization: `Token ${this.config.deepgramApiKey}`
        }
      });

      this.socket.on("open", resolve);
      this.socket.on("error", reject);
      this.socket.on("message", (data, isBinary) => {
        if (isBinary) {
          this.handlers.onAudio?.(data);
        }
      });
      this.socket.on("close", () => this.handlers.onClose?.());
    });
  }

  speakTextChunk(text) {
    if (!text || this.socket?.readyState !== WebSocket.OPEN) {
      return;
    }

    this.socket.send(JSON.stringify({ type: "Speak", text }));
    this.socket.send(JSON.stringify({ type: "Flush" }));
  }

  clear() {
    if (this.socket?.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify({ type: "Clear" }));
      this.socket.send(JSON.stringify({ type: "Close" }));
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
}
