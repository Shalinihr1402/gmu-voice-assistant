# Node Voice Backend

## Purpose

This backend replaces the PHP request/response voice path with a WebSocket-first real-time pipeline:

1. Browser streams microphone audio to Node over WebSocket
2. Node forwards audio to Deepgram live STT
3. Node streams transcript events back to browser
4. Node sends the final transcript to Gemini
5. Node streams partial text tokens back to browser
6. Node forwards text chunks to Deepgram streaming TTS
7. Node streams synthesized audio back to browser

## Run

1. Copy `.env.example` to `.env`
2. Install dependencies:
   `npm install`
3. Start the server:
   `npm run dev`

## Frontend WebSocket URL

`ws://localhost:8081/ws`

## Message Types

Client -> server:
- `session.init`
- `audio.chunk`
- `audio.end`
- `response.cancel`
- `session.reset`
- `ping`

Server -> client:
- `session.ready`
- `transcript.partial`
- `transcript.final`
- `llm.partial`
- `llm.final`
- `tts.audio`
- `tts.end`
- `turn.state`
- `error`
- `pong`
