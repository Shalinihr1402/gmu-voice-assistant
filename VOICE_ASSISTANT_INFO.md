# Voice Assistant - API Information

## Current Architecture

The project now uses a mixed voice pipeline:

- **Frontend:** React + browser media APIs
- **Speech-to-text:** Deepgram via `backend/deepgramTranscribe.php`
- **AI replies:** `backend/api.php` -> `backend/services/LlmService.php`
- **Fallback AI:** local Python service or rule-based PHP fallback
- **Speech output:** Browser `speechSynthesis`

## AI Provider Support

`LlmService` supports both hosted LLM providers:

### 1. Gemini
- Environment variable: `GEMINI_API_KEY`
- Optional model override: `GEMINI_MODEL`
- Default model: `gemini-2.5-flash`

### 2. OpenAI
- Environment variable: `OPENAI_API_KEY`
- Optional model override: `OPENAI_MODEL`
- Default model: `gpt-4.1-mini`

### Provider Selection

Set `LLM_PROVIDER` to control priority:

- `gemini` -> try Gemini first, then OpenAI
- `openai` -> try OpenAI first, then Gemini
- If unset, the app currently defaults to Gemini first

## Recommended Setup For Your Project

If you already created a Gemini API key, set:

- `GEMINI_API_KEY=your_key_here`
- `LLM_PROVIDER=gemini`

That is enough for natural AI replies without changing the React frontend.
