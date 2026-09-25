// Websoft Incidents -- Outlook Add-in taskpane logic (docs/outlook-addin.md).
//
// Served by the app itself at /outlook-addin/, so the API is on the same
// origin and a relative path reaches it.
const API_BASE = "/api";

const $ = (id) => document.getElementById(id);
const panels = ["login-panel", "channel-panel", "otp-panel", "app-panel"];

function show(panel) {
  for (const p of panels) $(p).hidden = p !== panel;
}

function getToken() {
  return sessionStorage.getItem("websoft_token");
}

function setToken(token) {
  if (token) sessionStorage.setItem("websoft_token", token);
  else sessionStorage.removeItem("websoft_token");
}

async function post(path, payload, token) {
  const res = await fetch(`${API_BASE}${path}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify(payload),
  });
  let data = {};
  try {
    data = await res.json();
  } catch {
    /* no body */
  }
  if (!res.ok) {
    const err = new Error(data.detail || data.message || "Request failed.");
    err.status = res.status;
    throw err;
  }
  return data;
}

// ---- Sign in: the same steps as the web app's login page ----------------

let pending = {}; // tokens between sign-in steps

// One sign-in response -> the next panel. Mirrors AuthController:
// ok | otp_required | otp_channel_required | must_change_password.
function handleLoginResult(data) {
  if (data.status === "must_change_password") {
    throw new Error("This account must set its own password first. Sign in to Websoft in your browser once, then come back here.");
  }
  if (data.status === "otp_channel_required") {
    pending = { channelToken: data.channel_token };
    $("channel-whatsapp").hidden = !(data.available_channels || []).includes("whatsapp");
    $("channel-email").hidden = !(data.available_channels || []).includes("email");
    show("channel-panel");
    return;
  }
  if (data.status === "otp_required") {
    pending = { otpToken: data.otp_token };
    $("otp-sent-to").textContent =
      data.channel === "whatsapp" ? "We sent a 6-digit sign-in code to your WhatsApp." : "We emailed you a 6-digit sign-in code.";
    $("otp-code").value = "";
    show("otp-panel");
    $("otp-code").focus();
    return;
  }
  if (data.status === "ok" && data.access_token) {
    if (data.ai_data_consent_required) {
      throw new Error("Please sign in to Websoft in your browser once to accept the PDPA declaration, then come back here.");
    }
    setToken(data.access_token);
    pending = {};
    showApp();
    return;
  }
  throw new Error("Unexpected sign-in response.");
}

async function withButton(button, errorEl, fn) {
  errorEl.textContent = "";
  button.disabled = true;
  try {
    await fn();
  } catch (err) {
    errorEl.textContent = err.message;
  } finally {
    button.disabled = false;
  }
}

// ---- The open email -----------------------------------------------------

function currentEmailPayload() {
  const item = Office.context.mailbox.item;
  return new Promise((resolve) => {
    item.body.getAsync(Office.CoercionType.Text, (bodyResult) => {
      resolve({
        sender_name: item.from ? item.from.displayName : "",
        sender_email: item.from ? item.from.emailAddress : "",
        subject: item.subject || "(no subject)",
        body: bodyResult.status === Office.AsyncResultStatus.Succeeded ? bodyResult.value : "",
      });
    });
  });
}

function showApp() {
  show("app-panel");
  currentEmailPayload().then((payload) => {
    $("email-summary").textContent = `From: ${payload.sender_name} <${payload.sender_email}>\nSubject: ${payload.subject}`;
  });
}

function showResult(text) {
  $("result-panel").hidden = false;
  $("result-text").textContent = text;
}

// An expired or revoked token sends the user back to sign in rather
// than failing every click.
async function act(path) {
  const payload = await currentEmailPayload();
  try {
    return await post(path, payload, getToken());
  } catch (err) {
    if (err.status === 401) {
      setToken(null);
      show("login-panel");
      $("login-error").textContent = "Your session has ended. Please sign in again.";
      return null;
    }
    throw err;
  }
}

Office.onReady(() => {
  if (getToken()) showApp();
  else show("login-panel");

  $("login-button").addEventListener("click", () =>
    withButton($("login-button"), $("login-error"), async () => {
      handleLoginResult(await post("/auth/login", { username: $("email").value.trim(), password: $("password").value }));
    }),
  );
  $("password").addEventListener("keydown", (e) => {
    if (e.key === "Enter") $("login-button").click();
  });

  for (const channel of ["email", "whatsapp"]) {
    $(`channel-${channel}`).addEventListener("click", () =>
      withButton($(`channel-${channel}`), $("channel-error"), async () => {
        handleLoginResult(await post("/auth/send-otp", { channel_token: pending.channelToken, channel }));
      }),
    );
  }

  $("otp-button").addEventListener("click", () =>
    withButton($("otp-button"), $("otp-error"), async () => {
      const code = $("otp-code").value.trim();
      if (!/^\d{6}$/.test(code)) throw new Error("Enter the 6-digit code.");
      handleLoginResult(await post("/auth/verify-otp", { otp_token: pending.otpToken, code }));
    }),
  );
  $("otp-code").addEventListener("keydown", (e) => {
    if (e.key === "Enter") $("otp-button").click();
  });
  $("otp-cancel").addEventListener("click", () => {
    pending = {};
    show("login-panel");
  });

  $("sign-out").addEventListener("click", () => {
    setToken(null);
    $("result-panel").hidden = true;
    show("login-panel");
  });

  $("convert-incident-button").addEventListener("click", () =>
    withButton($("convert-incident-button"), $("action-error"), async () => {
      const incident = await act("/incidents/from-email");
      if (!incident) return;
      showResult(`Logged as Incident ${incident.incident_number}.`);
    }),
  );

  $("convert-job-order-button").addEventListener("click", () =>
    withButton($("convert-job-order-button"), $("action-error"), async () => {
      const result = await act("/incidents/from-email/convert-to-job-order");
      if (!result) return;
      if (result.job_order_created) {
        showResult(`Job Order ${result.job_order_number} created from Incident ${result.incident.incident_number}.`);
      } else {
        // Confirmed 2026-09-12: falls back to a plain Incident rather
        // than failing outright -- shown here, not hidden.
        showResult(`No Job Order created: logged as Incident ${result.incident.incident_number} instead.\nReason: ${result.fallback_reason}`);
      }
    }),
  );
});
