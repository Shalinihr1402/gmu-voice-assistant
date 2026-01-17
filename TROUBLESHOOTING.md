# Voice Assistant Troubleshooting

## Important: Voice Assistant doesn't need PHP Backend! ✅

The Voice Assistant uses **Browser's Web Speech API** which is **100% client-side**. It does NOT use PHP backend.

## How Voice Assistant Works

1. **Speech Recognition**: Browser's built-in Web Speech API (client-side)
2. **Voice Commands**: Processed in JavaScript (client-side)
3. **Navigation**: Uses React Router (client-side)
4. **No PHP/Backend needed** for voice assistant functionality

## PHP Backend is only for:
- Student data API endpoints
- Course information
- Payment details
- Profile updates

**Voice Assistant works completely independently!**

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

**Issue: Shows network error**
- This is normal (ignored in code)
- Speech recognition works offline
- Should still work

**Issue: Not recognizing commands**
- Speak clearly and slowly
- Try commands: "Hello GMU", "Show my profile"
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

## PHP Backend is Separate

PHP backend (backend/api.php) is ONLY used for:
- `/api/student` - Student data
- `/api/courses` - Course information  
- `/api/fees` - Payment details

Voice Assistant does NOT call PHP backend at all!

---

## Quick Fix

If voice assistant is not working:
1. Use Chrome/Edge browser
2. Allow microphone permissions
3. Check browser console (F12) for errors
4. Speak clearly and try "Hello GMU"

**Remember: PHP backend issues will NOT affect voice assistant!**
