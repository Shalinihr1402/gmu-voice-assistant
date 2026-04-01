import http from "node:http";
import { WebSocketServer } from "ws";
import { config } from "./config.js";
import { SessionManager } from "./session/SessionManager.js";
import { VoiceGateway } from "./VoiceGateway.js";
import { safeJsonParse, sendJson } from "./utils/safeJson.js";

const sessionManager = new SessionManager();
const voiceGateway = new VoiceGateway({ config, sessionManager });

const server = http.createServer((req, res) => {
  if (req.url === "/health") {
    res.writeHead(200, { "Content-Type": "application/json" });
    res.end(JSON.stringify({ ok: true }));
    return;
  }

  res.writeHead(404, { "Content-Type": "application/json" });
  res.end(JSON.stringify({ error: "Not found" }));
});

const wss = new WebSocketServer({ server, path: "/ws" });

wss.on("connection", (ws) => {
  ws.on("message", async (data, isBinary) => {
    try {
      if (isBinary) {
        voiceGateway.forwardAudioChunk(ws, data);
        return;
      }

      const message = safeJsonParse(String(data));
      if (!message?.type) {
        sendJson(ws, { type: "error", message: "Invalid message payload." });
        return;
      }

      switch (message.type) {
        case "session.init":
          await voiceGateway.initializeSession(ws, message);
          break;
        case "audio.start":
          await voiceGateway.beginAudioStream(ws);
          break;
        case "audio.chunk":
          if (message.audio) {
            voiceGateway.forwardAudioChunk(ws, Buffer.from(message.audio, "base64"));
          }
          break;
        case "audio.end":
          voiceGateway.endAudioStream(ws);
          break;
        case "response.cancel":
          await voiceGateway.cancelResponse(ws);
          break;
        case "session.reset":
          if (ws.session) {
            sessionManager.resetSession(ws.session);
          }
          sendJson(ws, { type: "session.reset.ok" });
          break;
        case "ping":
          sendJson(ws, { type: "pong", ts: Date.now() });
          break;
        default:
          sendJson(ws, { type: "error", message: `Unsupported message type: ${message.type}` });
      }
    } catch (error) {
      sendJson(ws, {
        type: "error",
        message: error instanceof Error ? error.message : "Unexpected server error."
      });
    }
  });

  ws.on("close", async () => {
    if (ws.session) {
      await voiceGateway.cancelResponse(ws);
    }
  });
});

server.listen(config.port, () => {
  console.log(`Voice backend listening on http://localhost:${config.port}`);
});
