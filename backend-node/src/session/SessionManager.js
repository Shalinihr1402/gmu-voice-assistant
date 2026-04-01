import crypto from "node:crypto";
import { ConversationMemory } from "./ConversationMemory.js";

export class SessionManager {
  constructor() {
    this.sessions = new Map();
  }

  createOrGet(sessionId, initialContext = {}) {
    const key = sessionId || crypto.randomUUID();

    if (!this.sessions.has(key)) {
      this.sessions.set(key, {
        id: key,
        userContext: { ...initialContext },
        memory: new ConversationMemory(),
        activeTurnId: null,
        sttClient: null,
        ttsClient: null,
        abortController: null
      });
    } else if (Object.keys(initialContext).length) {
      const session = this.sessions.get(key);
      session.userContext = { ...session.userContext, ...initialContext };
    }

    return this.sessions.get(key);
  }

  clearTurnState(session) {
    session.activeTurnId = null;
    session.abortController = null;
  }

  resetSession(session) {
    session.memory.reset();
    this.clearTurnState(session);
  }
}
