import { buildSystemPrompt } from "./PromptBuilder.js";

export class GeminiStreamClient {
  constructor(config) {
    this.config = config;
  }

  async generate({ text, session, onPartial, signal }) {
    if (!this.config.geminiApiKey) {
      throw new Error("Missing GEMINI_API_KEY.");
    }

    const memoryContext = session.memory.getContext();
    const systemPrompt = buildSystemPrompt(session.userContext, memoryContext);
    const contents = [
      ...memoryContext.recentMessages.map((message) => ({
        role: message.role === "assistant" ? "model" : "user",
        parts: [{ text: message.text }]
      })),
      {
        role: "user",
        parts: [{ text }]
      }
    ];

    const response = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(this.config.geminiModel)}:streamGenerateContent?alt=sse`,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "x-goog-api-key": this.config.geminiApiKey
        },
        body: JSON.stringify({
          system_instruction: {
            parts: [{ text: systemPrompt }]
          },
          contents,
          generationConfig: {
            temperature: 0.5,
            topP: 0.9,
            maxOutputTokens: 220
          }
        }),
        signal
      }
    );

    if (!response.ok || !response.body) {
      const detail = await response.text().catch(() => "");
      throw new Error(detail || `Gemini request failed with ${response.status}.`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";
    let finalText = "";

    while (true) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }

      buffer += decoder.decode(value, { stream: true });
      const events = buffer.split("\n\n");
      buffer = events.pop() || "";

      for (const event of events) {
        const line = event
          .split("\n")
          .find((item) => item.startsWith("data: "));

        if (!line) {
          continue;
        }

        const jsonText = line.slice(6).trim();
        if (!jsonText || jsonText === "[DONE]") {
          continue;
        }

        const payload = JSON.parse(jsonText);
        const parts = payload.candidates?.[0]?.content?.parts || [];
        const chunkText = parts
          .map((part) => String(part.text || ""))
          .join("")
          .trim();

        if (!chunkText) {
          continue;
        }

        finalText = chunkText;
        onPartial?.(chunkText);
      }
    }

    return finalText.trim();
  }
}
