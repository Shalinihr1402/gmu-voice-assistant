import Vapi from "https://esm.sh/@vapi-ai/web@2.5.2";

let vapi = null;
let eventHandler = null;

function emit(type, payload = {}) {
  if (!eventHandler) return;
  try {
    eventHandler(JSON.stringify({ type, ...payload }));
  } catch (error) {
    console.error("GMU Vapi bridge event failed", error);
  }
}

window.gmuVapiSetEventHandler = (handler) => {
  eventHandler = typeof handler === "function" ? handler : null;
};

window.gmuVapiStart = async (configJson) => {
  const config = JSON.parse(configJson || "{}");
  if (!config.public_key) {
    throw new Error("Missing Vapi public key.");
  }

  if (vapi) {
    try {
      vapi.stop();
    } catch (_) {}
    vapi = null;
  }

  vapi = new Vapi(config.public_key);
  vapi.on("call-start", () => emit("call-start"));
  vapi.on("call-end", () => emit("call-end"));
  vapi.on("speech-start", () => emit("speech-start"));
  vapi.on("speech-end", () => emit("speech-end"));
  vapi.on("message", (message) => emit("message", { message }));
  vapi.on("error", (error) => {
    emit("error", {
      message: error?.message || "Vapi voice session failed.",
      error,
    });
  });

  await vapi.start(config.assistant, config.assistant_overrides || {});
};

window.gmuVapiStop = () => {
  if (!vapi) return;
  try {
    vapi.stop();
  } finally {
    vapi = null;
  }
};
