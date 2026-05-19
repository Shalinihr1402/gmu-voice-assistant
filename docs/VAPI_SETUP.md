# Vapi Setup for GMU VoiceBot

This project now uses Vapi as the primary voice transport behind the existing round GMU button.

## Architecture

```text
GMU round button
-> Vapi Web SDK
-> Vapi STT + LLM + TTS
-> Vapi tool: gmu_voice_assistant
-> backend/vapiWebhook.php
-> backend/services/VapiToolService.php
-> existing backend/api.php
-> VoiceUnderstandingService.php + existing DB/RAG logic
-> Vapi speaks reply
```

Deepgram and ElevenLabs code is still present as fallback/legacy code, but the button now tries Vapi first.

## Backend .env

Create or update:

```text
backend/.env
```

Minimum:

```env
VAPI_PUBLIC_KEY=your_vapi_public_key
VAPI_WEBHOOK_URL=https://your-ngrok-or-domain/gmu-voice-assistant/backend/vapiWebhook.php
VOICEBOT_INTERNAL_API_URL=http://localhost:8080/gmu-voice-assistant/backend/api.php
```

Recommended model/voice settings:

```env
VAPI_MODEL_PROVIDER=openai
VAPI_MODEL=gpt-4o-mini
VAPI_TRANSCRIBER_PROVIDER=deepgram
VAPI_TRANSCRIBER_MODEL=nova-3
VAPI_VOICE_PROVIDER=11labs
VAPI_VOICE_ID=EXAVITQu4vr4xnSDxMaL
```

Optional if you create an assistant in the Vapi dashboard:

```env
VAPI_ASSISTANT_ID=your_assistant_id
```

If `VAPI_ASSISTANT_ID` is empty, the app starts Vapi with a generated assistant object from PHP.

## Local Testing With Ngrok

Vapi must reach your PHP webhook from the internet.

```bash
ngrok http 8080
```

Then set:

```env
VAPI_WEBHOOK_URL=https://your-ngrok-url.ngrok-free.app/gmu-voice-assistant/backend/vapiWebhook.php
```

## Vapi Dashboard Settings

If using the generated assistant object, you only need the public key.

If using Vapi dashboard assistant:

- Model provider: OpenAI
- Model: `gpt-4o-mini` or your preferred low-latency model
- Transcriber: Deepgram
- Transcriber model: `nova-3`
- Language: multilingual/multi if available, otherwise rely on the prompt and tool
- Voice provider: ElevenLabs or Vapi supported voice provider
- Voice: choose female or male voice ID
- Server URL: your `VAPI_WEBHOOK_URL`
- Tool name: `gmu_voice_assistant`

## Assistant Prompt

Use this prompt in Vapi if you configure the dashboard manually:

```text
You are GMU VoiceBot, a production university voice assistant for Indian students.
You understand English, Hindi, Kannada, and code-mixed speech.
Always call gmu_voice_assistant for university data, navigation, attendance, result, fee, profile, registration, certificate, course, faculty, RAG, and follow-up questions.
Do not guess student data.
Keep answers short and natural for voice.
For Kannada, natural Kannada or Kanglish is allowed for university terms.
For Hindi, use simple Hindi with common English university terms.
Examples:
- home page ಹೋಗು means open home page
- registration page ತೆರೆಯು means open registration page
- DBMS attendance ಹೇಳು means show DBMS attendance
- faculty details बताओ means tell faculty details
- fees elli pay madodu means explain fee payment
When calling the tool, include session_token exactly from variable student_session_token.
After the tool returns, speak the reply field. If client_action is present, say the action is being opened.
```

## Tool Schema

```json
{
  "type": "function",
  "function": {
    "name": "gmu_voice_assistant",
    "description": "Route every GMU university voice query to the PHP backend. Supports English, Hindi, Kannada, and code-mixed speech.",
    "parameters": {
      "type": "object",
      "properties": {
        "query": { "type": "string" },
        "language": { "type": "string", "enum": ["en", "hi", "kn", "multi"] },
        "session_token": { "type": "string" }
      },
      "required": ["query", "session_token"]
    }
  }
}
```

## Voice Gender

Set voice gender by changing `VAPI_VOICE_ID`.

Examples:

```env
# Female voice example
VAPI_VOICE_ID=EXAVITQu4vr4xnSDxMaL

# Male voice: replace with your chosen Vapi/ElevenLabs voice id
VAPI_VOICE_ID=your_male_voice_id
```

Keep private Vapi API keys out of React. Only `VAPI_PUBLIC_KEY` is returned to the browser by `vapiConfig.php`.
