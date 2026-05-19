# GMU VoiceBot Assistant

GMU VoiceBot Assistant is a multilingual AI voice assistant for a university ERP system. It helps students access academic and administrative information through natural voice conversations in English, Hindi, Kannada, Hinglish, Kanglish, and mixed Indian student speech.

The project combines a React frontend, PHP REST APIs, MySQL data, Vapi voice calling, Deepgram speech-to-text, OpenAI intelligence, and optional ElevenLabs/OpenAI text-to-speech.

## Highlights

- Multilingual voice assistant for Indian university students
- English, Hindi, Kannada, Hinglish, Kanglish, and code-mixed query support
- Real-time Vapi voice conversation flow
- ERP navigation using voice commands
- Student profile, attendance, fees, registration, results, certificates, courses, and grievance support
- MySQL-backed student data retrieval
- PHP service layer for intent handling, multilingual understanding, Vapi tools, and assistant configuration
- React UI with a floating voice assistant widget
- Navigation safety guards to prevent duplicate redirects and transcript replay loops

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
```

## Tech Stack

| Layer | Technologies |
| --- | --- |
| Frontend | React.js, Vite, JavaScript, HTML5, CSS3 |
| Backend | PHP, REST APIs, service-based architecture |
| Database | MySQL |
| Voice AI | Vapi AI |
| Speech-to-Text | Deepgram |
| LLM | OpenAI-compatible model configuration |
| Text-to-Speech | OpenAI TTS / ElevenLabs support |
| Local Server | XAMPP Apache + MySQL |

## Architecture

```text
Student Voice
    -> Vapi Web SDK
    -> Deepgram transcription
    -> Vapi assistant model
    -> gmu_voice_assistant tool call
    -> PHP Vapi webhook
    -> VapiToolService / VoiceUnderstandingService
    -> Internal PHP APIs
    -> MySQL database
    -> Structured reply + optional client_action
    -> React UI executes navigation once
```

### Backend Responsibilities

- `VapiAssistantConfigService.php` builds the generated Vapi assistant configuration.
- `VapiToolService.php` handles tool calls, safe navigation, language switching, result filters, and voice-ready replies.
- `VoiceUnderstandingService.php` detects multilingual intent, entities, and safe navigation signals.
- `MultilingualUnderstandingService.php` normalizes multilingual text and extracts hints without directly triggering navigation.
- `api.php` routes ERP queries to the correct intent controllers.

### Frontend Responsibilities

- Starts and stops the Vapi voice session.
- Displays transcript, assistant reply, suggestions, and source.
- Executes backend `client_action` commands such as navigation.
- Prevents duplicate navigation with a lock and transcript reset.

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

### 4. Safe Vapi Tool Flow

The Vapi prompt is designed to avoid unnecessary tool calls for greetings and filler words. The backend also prevents:

- Duplicate navigation
- Transcript replay
- Accidental dashboard opening
- Navigation from partial transcript fragments
- Certificate status accidentally opening the certificate page

## Project Structure

```text
gmu-voice-assistant/
+-- backend/
|   +-- api.php
|   +-- vapiConfig.php
|   +-- vapiWebhook.php
|   +-- schema.sql
|   +-- config/
|   |   +-- db.php
|   |   +-- env.php
|   +-- intents/
|   +-- services/
|       +-- VapiAssistantConfigService.php
|       +-- VapiToolService.php
|       +-- VoiceUnderstandingService.php
|       +-- MultilingualUnderstandingService.php
|       +-- LlmService.php
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

- XAMPP with Apache and MySQL
- Node.js and npm
- Git
- A Vapi account
- API keys for the providers you configure, such as OpenAI, Deepgram, and optionally ElevenLabs

### 1. Clone the Repository

```bash
git clone https://github.com/shalini1402/gmu-voice-assistant.git
cd gmu-voice-assistant
```

### 2. Move Project to XAMPP

Place the project inside:

```text
C:\xampp\htdocs\gmu-voice-assistant
```

Start Apache and MySQL from the XAMPP Control Panel.

### 3. Install Frontend Dependencies

This project uses Vite at the repository root.

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

Database configuration is located at:

```text
backend/config/db.php
```

Default local settings:

```php
$host = "127.0.0.1";
$user = "root";
$password = "";
$database = "gmu_voice_assistant";
```

### 5. Backend Environment Setup

Create or update:

```text
backend/.env
```

Example:

```env
VAPI_PUBLIC_KEY=your_vapi_public_key
VAPI_WEBHOOK_URL=https://your-ngrok-url.ngrok-free.dev/gmu-voice-assistant/backend/vapiWebhook.php
VOICEBOT_INTERNAL_API_URL=http://localhost:8080/gmu-voice-assistant/backend/api.php

VAPI_MODEL_PROVIDER=openai
VAPI_MODEL=gpt-4o-mini
VAPI_TRANSCRIBER_PROVIDER=deepgram
VAPI_TRANSCRIBER_MODEL=nova-3
VAPI_VOICE_PROVIDER=openai
VAPI_VOICE_ID=shimmer
VAPI_VOICE_MODEL=gpt-4o-mini-tts
```

Do not commit real API keys to GitHub.

### 6. Expose Local Backend for Vapi

Use ngrok while testing locally:

```bash
C:\ngrok\ngrok.exe http 8080
```

Set `VAPI_WEBHOOK_URL` to:

```text
https://your-ngrok-url.ngrok-free.dev/gmu-voice-assistant/backend/vapiWebhook.php
```

Restart Apache after changing `.env`.

## Running the Project

Open the app in the browser:

```text
http://localhost:8080/gmu-voice-assistant
```

Or use Vite during development:

```text
http://localhost:5173
```

Make sure Apache, MySQL, and ngrok are running when testing Vapi calls.

## Voice Assistant Flow

1. Student starts the GMU VoiceBot from the React UI.
2. Vapi starts a voice session.
3. Deepgram transcribes the student's speech.
4. Vapi decides whether to call the `gmu_voice_assistant` tool.
5. PHP backend processes the request.
6. Backend returns a reply and optional `client_action`.
7. React displays the reply and executes navigation once if required.

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
- React navigation lock

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
Login works
Vapi public key loads
ngrok webhook reaches vapiWebhook.php
Apache and MySQL are running
Voice session starts successfully
Registration navigation opens once
Come back returns home once
Certificate status does not navigate
Open certificate page navigates correctly
Hindi, Kannada, and English queries work
Fees and result queries fetch database data
```

## Future Improvements

- Admin dashboard for managing assistant settings
- Production OAuth or SSO authentication
- Better analytics for voice queries
- Expanded university knowledge base
- Deployment on a cloud server
- CI/CD pipeline and automated tests
- Role-based assistant behavior for students, faculty, and admins

## Author

Developed by Shalini as a full-stack AI voice assistant project for a university ERP use case.

## License

This project is intended for academic, portfolio, and demonstration purposes. Add a license file before public production use.