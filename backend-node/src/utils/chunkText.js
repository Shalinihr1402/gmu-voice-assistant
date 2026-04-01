export function splitIntoSpeechChunks(text) {
  return String(text || "")
    .replace(/\s+/g, " ")
    .trim()
    .match(/[^.!?]+[.!?]+|[^.!?]+$/g)?.map((item) => item.trim())
    .filter(Boolean) || [];
}
