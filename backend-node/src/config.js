import dotenv from "dotenv";

dotenv.config();

export const config = {
  port: Number.parseInt(process.env.PORT || "8081", 10),
  deepgramApiKey: process.env.DEEPGRAM_API_KEY || "",
  deepgramSttModel: process.env.DEEPGRAM_STT_MODEL || "nova-3",
  deepgramTtsModel: process.env.DEEPGRAM_TTS_MODEL || "aura-2-asteria-en",
  deepgramTtsSampleRate: Number.parseInt(process.env.DEEPGRAM_TTS_SAMPLE_RATE || "24000", 10),
  geminiApiKey: process.env.GEMINI_API_KEY || process.env.GOOGLE_API_KEY || "",
  geminiModel: process.env.GEMINI_MODEL || "gemini-2.5-flash"
};
