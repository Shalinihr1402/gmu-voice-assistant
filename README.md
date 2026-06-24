# GMU VoiceBot Assistant

GMU VoiceBot Assistant is a multilingual AI voice assistant for a university ERP system. It helps students access academic and administrative information through natural voice conversations in English, Hindi, Kannada, Hinglish, Kanglish, and mixed Indian student speech.

The project combines a **Flutter mobile app**, PHP REST APIs, MySQL data, Vapi voice calling, Deepgram speech-to-text, OpenAI intelligence, and optional ElevenLabs/OpenAI text-to-speech.

## Highlights

- Multilingual voice assistant for Indian university students
- English, Hindi, Kannada, Hinglish, Kanglish, and code-mixed query support
- Real-time Vapi voice conversation flow
- Flutter mobile app with floating draggable voice orb (Bixby/Gemini-style UI)
- ERP navigation using voice commands
- Student profile, attendance, fees, registration, results, certificates, courses, and grievance support
- MySQL-backed student data retrieval
- PHP service layer for intent handling, multilingual understanding, Vapi tools, and assistant configuration
- Bus timing queries with multilingual keyword detection (English, Hindi, Kannada)
- Background noise suppression via aggressive `stopSpeakingPlan` thresholds

## Demo Use Cases

Students can ask questions such as:

```text
How can I pay my fees?
Show my DBMS attendance.
Open registration page.
Come back.
Show 4th semester result.
Speak in Kannada.
Fees elli pay madodu?
Profile kholo.
Registration page torisu.
Open my competency certificate.
Bus ka time kya hai?
Bus yavaga baruthu?
```

## Tech Stack

| Layer | Technologies |
| --- | --- |
| Mobile App | Flutter (Dart), Android / iOS |
| Frontend (Web) | React.js, Vite, JavaScript, HTML5, CSS3 |
| Backend | PHP, REST APIs, service-based architecture |
| Database | MySQL |
| Voice AI | Vapi AI |
| Speech-to-Text | Deepgram |
| LLM | OpenAI-compatible model configuration |
| Text-to-Speech | OpenAI TTS / ElevenLabs support |
| Local Server | XAMPP Apache (port 8080) + MySQL |
| Tunnel | ngrok (HTTPS tunnel for Vapi webhooks) |

## Architecture

```text
Student Voice (Flutter App)
    -> Vapi SDK (Flutter)
    -> Deepgram transcription
    -> Vapi assistant model
    -> gmu_voice_assistant tool call
    -> PHP Vapi webhook (via ngrok HTTPS tunnel)
    -> VapiToolService / VoiceUnderstandingService
    -> Internal PHP APIs
    -> MySQL database
    -> Structured reply + optional client_action
    -> Flutter UI executes navigation / displays response
```

### Backend Responsibilities

- `VapiAssistantConfigService.php` builds the Vapi assistant configuration with aggressive noise suppression (`stopSpeakingPlan`: numWords=10, voiceSeconds=1.2, backoffSeconds=3.0).
- `VapiToolService.php` handles tool calls, safe navigation, language switching, result filters, and voice-ready replies.
- `VoiceUnderstandingService.php` detects multilingual intent, entities, and safe navigation signals.
- `MultilingualUnderstandingService.php` normalizes multilingual text and extracts hints without directly triggering navigation.
- `ERPQueryService.php` handles ERP queries including bus timings with multilingual keyword detection.
- `api.php` routes ERP queries to the correct intent controllers with proper CORS handling.

### Flutter App Responsibilities

- Starts and stops the Vapi voice session.
- Displays floating draggable voice orb with idle / listening / speaking / connecting states.
- Orb animates: 72×72 (idle) → 88×88 (listening) → 180×72 pill (speaking) with glassmorphism.
- Snap-to-edge drag behaviour with SafeArea padding.
- Login via PHP backend with ngrok HTTPS tunnel support (`SameSite=None; Secure` session cookies).
- Configurable backend URL via `--dart-define=GMU_BACKEND_URL=...` at build time.

## Key Features

### 1. Multilingual Student Support

The assistant supports:

- English
- Hindi
- Kannada
- Hinglish
- Kanglish
- Mixed-language student queries

Examples:

```text
DBMS attendance show maadu
result check karna hai
registration page torisu
fees elli pay madodu
profile kholo
bus kab aata hai
bus yavaga baruthu
```

### 2. ERP Voice Navigation

Supported pages include:

- Home
- Student Dashboard
- Profile
- Registration
- Payment Portal
- Results
- Digital Competency Certificate

Navigation is guarded so pages open only when the user explicitly asks to open, go, navigate, show, or return to a page.

### 3. Student Data Queries

The assistant can answer ERP queries such as:

- Attendance by subject
- Semester result and result filters
- Fee balance and payment guidance
- Registration status
- Hall ticket status
- Certificate status
- Course details and course codes
- Profile and USN details
- Payment grievance guidance
- **College bus timings** (Morning: 7:00 AM, 8:00 AM, 9:00 AM · Evening: 4:30 PM, 6:00 PM · Routes: Davangere, Harihar, Ranebennur)

### 4. Flutter Voice Orb UI

The Flutter app features a Bixby/Gemini-style floating voice orb:

- **Idle**: 72×72 circular orb with mic icon, draggable anywhere on screen
- **Listening**: 88×88 pulsing orb with mic glow animation
- **Connecting**: orb with 3 pulsing dots
- **Speaking**: 180×72 pill shape with live sound wave visualiser
- Glassmorphism blur effect (`BackdropFilter`)
- Snap-to-edge on drag release
- Close button (28×28) above the orb

### 5. Safe Vapi Tool Flow

The Vapi prompt is designed to avoid unnecessary tool calls for greetings and filler words. The backend also prevents:

- Duplicate navigation
- Transcript replay
- Accidental dashboard opening
- Navigation from partial transcript fragments
- Certificate status accidentally opening the certificate page
- Bot interruption from background/ambient noise

## Project Structure

```text
gmu-voice-assistant/
+-- backend/
|   +-- api.php
|   +-- vapiConfig.php
|   +-- vapiWebhook.php
|   +-- login.php
|   +-- schema.sql
|   +-- .env
|   +-- config/
|   |   +-- db.php
|   |   +-- env.php
|   |   +-- cors.php
|   +-- intents/
|   |   +-- controllers/
|   +-- services/
|       +-- VapiAssistantConfigService.php
|       +-- VapiToolService.php
|       +-- VoiceUnderstandingService.php
|       +-- MultilingualUnderstandingService.php
|       +-- ERPQueryService.php
|       +-- LlmService.php
+-- flutter_app/
|   +-- lib/
|   |   +-- main.dart
|   +-- android/
|   +-- ios/
|   +-- pubspec.yaml
+-- src/
|   +-- components/
|   |   +-- VoiceAssistant.jsx
|   +-- pages/
|   +-- utils/
+-- public/
+-- dist/
+-- package.json
+-- vite.config.js
+-- README.md
```

## Installation

### Prerequisites

Install the following:

- XAMPP with Apache (port 8080) and MySQL
- Flutter SDK (for mobile app)
- Node.js and npm (for web frontend)
- Git
- A Vapi account
- ngrok (for local HTTPS tunnelling)
- API keys for OpenAI, Deepgram, and optionally ElevenLabs

### 1. Clone the Repository

```bash
git clone https://github.com/Shalinihr1402/gmu-voice-assistant.git
cd gmu-voice-assistant
```

### 2. Move Project to XAMPP

Place the project inside:

```text
C:\xampp\htdocs\gmu-voice-assistant
```

Start Apache and MySQL from the XAMPP Control Panel. Apache should run on **port 8080** (port 80 is typically blocked on Windows).

### 3. Install Frontend Dependencies (Web)

```bash
npm install
npm run dev
```

For production build:

```bash
npm run build
```

### 4. Database Setup

Create the database:

```sql
CREATE DATABASE gmu_voice_assistant;
```

Import the schema:

```text
backend/schema.sql
```

Database configuration is at `backend/config/db.php`. Default local settings:

```php
$host = "127.0.0.1";
$user = "root";
$password = "";
$database = "gmu_voice_assistant";
```

### 5. Backend Environment Setup

Create or update `backend/.env`:

```env
VAPI_PUBLIC_KEY=your_vapi_public_key
VAPI_WEBHOOK_URL=https://your-ngrok-url.ngrok-free.dev/gmu-voice-assistant/backend/vapiWebhook.php
VOICEBOT_INTERNAL_API_URL=http://localhost:8080/gmu-voice-assistant/backend/api.php
GMU_CORS_ORIGINS=https://your-ngrok-url.ngrok-free.dev

VAPI_MODEL_PROVIDER=openai
VAPI_MODEL=gpt-4o-mini
VAPI_TRANSCRIBER_PROVIDER=deepgram
VAPI_TRANSCRIBER_MODEL=nova-3
VAPI_VOICE_PROVIDER=openai
VAPI_VOICE_ID=shimmer
VAPI_VOICE_MODEL=gpt-4o-mini-tts
```

Do not commit real API keys to GitHub.

### 6. Expose Local Backend via ngrok

```bash
C:\ngrok\ngrok.exe http 8080
```

Copy the HTTPS URL and set it in `.env` as both `VAPI_WEBHOOK_URL` and `GMU_CORS_ORIGINS`. Restart Apache after changing `.env`.

### 7. Run the Flutter App (Mobile)

Connect your Android device via USB with USB debugging enabled.

```bash
cd flutter_app
flutter pub get
flutter run -d <DEVICE_ID> --dart-define=GMU_BACKEND_URL=https://your-ngrok-url.ngrok-free.dev/gmu-voice-assistant/backend
```

Find your device ID with:

```bash
flutter devices
```

> **Note:** `--dart-define` is a compile-time constant. The app must be fully rebuilt (not just hot-reloaded) when the backend URL changes.

## Running the Project

Open the web app in the browser:

```text
http://localhost:8080/gmu-voice-assistant
```

Or use Vite during development:

```text
http://localhost:5173
```

Make sure Apache, MySQL, and ngrok are running when testing Vapi calls.

## Voice Assistant Flow

1. Student opens the Flutter app and logs in.
2. Student taps the floating voice orb.
3. Vapi starts a voice session.
4. Deepgram transcribes the student's speech.
5. Vapi decides whether to call the `gmu_voice_assistant` tool.
6. PHP backend processes the request and queries MySQL.
7. Backend returns a reply and optional `client_action`.
8. Flutter displays the reply and executes navigation if required.

## Example Commands

```text
Open registration page
Come back
Show my profile
How can I pay my fees?
Apply payment grievance
Show 4th semester result
Show all semester results
DBMS attendance
Course code of DBMS
Speak in Kannada
Hindi mein baat karo
Bus ka time kya hai
Bus yavaga baruthu
What time does the evening bus leave?
```

## Production Stability Work

The project includes safeguards for:

- Duplicate Vapi tool calls
- Navigation cooldown
- Transcript replay prevention
- Partial transcript filtering
- Explicit-only navigation
- Multilingual intent normalization
- Backend-controlled page routing
- Flutter navigation lock
- Background noise suppression via `stopSpeakingPlan` thresholds
- CORS handling for ngrok HTTPS tunnel with `SameSite=None; Secure` session cookies

## Security Notes

- Store API keys in `backend/.env`.
- Never commit `.env` with real credentials.
- Restrict production CORS settings.
- Use HTTPS for webhook URLs.
- Validate session tokens before returning private student data.
- Sanitize and validate all database inputs.

## Testing Checklist

Before demo or deployment, verify:

```text
Login works from Flutter app via ngrok
Vapi public key loads
ngrok webhook reaches vapiWebhook.php
Apache on port 8080 and MySQL are running
Voice session starts successfully
Floating orb shows idle / listening / speaking states
Registration navigation opens once
Come back returns home once
Certificate status does not navigate
Open certificate page navigates correctly
Hindi, Kannada, and English queries work
Bus timing queries return correct schedule
Fees and result queries fetch database data
Background noise does not trigger false state changes
```

## Future Improvements

- Admin dashboard for managing assistant settings
- Production OAuth or SSO authentication
- Better analytics for voice queries
- Expanded university knowledge base
- Deployment on a cloud server
- CI/CD pipeline and automated tests
- Role-based assistant behaviour for students, faculty, and admins
- iOS build and App Store distribution

## Author

Developed by Shalini as a full-stack AI voice assistant project for a university ERP use case.

## License

This project is intended for academic, portfolio, and demonstration purposes. Add a license file before public production use.
