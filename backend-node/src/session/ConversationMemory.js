const RECENT_MESSAGE_LIMIT = 10;
const SUMMARY_CHAR_LIMIT = 1800;

export class ConversationMemory {
  constructor() {
    this.summary = "";
    this.recentMessages = [];
  }

  getContext() {
    return {
      summary: this.summary,
      recentMessages: [...this.recentMessages]
    };
  }

  addTurn(userText, assistantText) {
    this.recentMessages.push(
      { role: "user", text: String(userText || "").trim() },
      { role: "assistant", text: String(assistantText || "").trim() }
    );

    this.compact();
  }

  reset() {
    this.summary = "";
    this.recentMessages = [];
  }

  compact() {
    const overflow = this.recentMessages.length - RECENT_MESSAGE_LIMIT;
    if (overflow <= 0) {
      return;
    }

    const olderMessages = this.recentMessages.slice(0, overflow);
    this.recentMessages = this.recentMessages.slice(-RECENT_MESSAGE_LIMIT);
    this.summary = mergeSummary(this.summary, summarizeMessages(olderMessages));
  }
}

function summarizeMessages(messages) {
  const lines = [];
  let pendingUser = null;

  for (const message of messages) {
    const text = normalizeSummaryText(message.text);
    if (!text) {
      continue;
    }

    if (message.role === "user") {
      pendingUser = text;
      continue;
    }

    if (message.role === "assistant") {
      if (pendingUser) {
        lines.push(`User asked: ${pendingUser} Assistant answered: ${text}`);
        pendingUser = null;
      } else {
        lines.push(`Assistant answered: ${text}`);
      }
    }
  }

  if (pendingUser) {
    lines.push(`User asked: ${pendingUser}`);
  }

  return lines.join(" ");
}

function mergeSummary(existingSummary, nextSummary) {
  const combined = `${existingSummary || ""} ${nextSummary || ""}`.replace(/\s+/g, " ").trim();
  if (combined.length <= SUMMARY_CHAR_LIMIT) {
    return combined;
  }

  return combined.slice(-SUMMARY_CHAR_LIMIT);
}

function normalizeSummaryText(text) {
  const normalized = String(text || "").replace(/\s+/g, " ").trim();
  if (normalized.length <= 220) {
    return normalized;
  }

  return `${normalized.slice(0, 217).trim()}...`;
}
