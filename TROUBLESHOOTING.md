# Voice Assistant Troubleshooting

## Important: Voice Assistant uses PHP backend for AI replies

The current voice assistant is not fully client-side anymore.

## How Voice Assistant Works

1. **Microphone capture**: Browser media APIs
2. **Speech Recognition**: Deepgram transcription through `backend/deepgramTranscribe.php`
3. **Voice Commands + AI**: React sends text to `backend/api.php`
4. **Navigation**: Uses React Router for page changes
5. **Voice Output**: Browser speech synthesis

## PHP Backend is required for:
- Deepgram transcription
- User session lookup
- Student data API endpoints
- Gemini/OpenAI AI replies

---

## If Voice Assistant is Not Responding:

### 1. Check Browser Support
- ✅ **Chrome/Edge**: Best support (recommended)
- ⚠️ **Safari**: Limited support
- ❌ **Firefox**: Not supported

### 2. Check Microphone Permissions
- Browser should ask for microphone permission
- Check browser settings: Settings → Privacy → Microphone
- Allow microphone access for localhost

### 3. Check Console for Errors
- Open Browser Developer Tools (F12)
- Check Console tab for any errors
- Common errors:
  - "Microphone permission denied"
  - "Speech recognition not supported"

### 4. Test Voice Assistant
1. Click the 🤖 robot icon (bottom right)
2. Click "Start Listening"
3. Speak clearly: "Hello GMU"
4. Check if it recognizes your voice

### 5. Common Issues

**Issue: Not responding at all**
- Check if microphone is connected
- Check browser permissions
- Try in Chrome/Edge browser

**Issue: AI replies are failing**
- Check `GEMINI_API_KEY` or `OPENAI_API_KEY`
- If you want Gemini first, set `LLM_PROVIDER=gemini`
- Confirm Apache/PHP can read those environment variables

**Issue: Not recognizing commands**
- Speak clearly and slowly
- Try commands like "show my profile" or "what is my fee balance"
- Check if transcript shows what you said

---

## Testing Steps

1. **Open browser** (Chrome recommended)
2. **Go to**: http://localhost:3000
3. **Click** the 🤖 robot icon
4. **Click** "Start Listening" button
5. **Speak**: "Hello GMU"
6. **Check** if it responds

---

## PHP Backend Endpoints Used By VoiceBot

- `backend/deepgramTranscribe.php` for speech-to-text
- `backend/api.php` for intent handling and AI replies

---

## Quick Fix

If voice assistant is not working:
1. Use Chrome/Edge browser
2. Allow microphone permissions
3. Check browser console (F12) for errors
4. Speak clearly and try "Hello GMU"

**Remember: PHP or API key issues can affect voice transcription and AI replies.**
