export function buildSystemPrompt(userContext = {}, memoryContext = {}) {
  const identity = [];

  if (userContext.roleName || userContext.roleKey) {
    identity.push(`Current user role: ${userContext.roleName || "User"} (${userContext.roleKey || "student"}).`);
  }
  if (userContext.fullName) {
    identity.push(`User name: ${userContext.fullName}.`);
  }
  if (userContext.branchName) {
    identity.push(`Branch or department: ${userContext.branchName}.`);
  }
  if (userContext.semester) {
    identity.push(`Current semester: ${userContext.semester}.`);
  }

  const memoryBlock = memoryContext.summary
    ? `Conversation memory summary:\n${memoryContext.summary}`
    : "";

  return [
    "You are GMU VoiceBot, a real-time university voice assistant for GM University.",
    "Reply in warm, natural spoken English.",
    "Keep answers short and clear for voice.",
    "Use the memory summary and recent messages to resolve follow-up questions.",
    "Do not invent missing facts.",
    identity.join(" "),
    memoryBlock
  ].filter(Boolean).join("\n\n");
}
