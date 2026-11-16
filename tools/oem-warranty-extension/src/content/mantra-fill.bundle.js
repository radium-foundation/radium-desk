(() => {
  // src/lib/constants.js
  var MSG = {
    HANDOFF_REQUEST: "OEM_HANDOFF_REQUEST",
    HANDOFF_CLAIM: "OEM_HANDOFF_CLAIM",
    HANDOFF_CLAIM_RESULT: "OEM_HANDOFF_CLAIM_RESULT",
    HANDOFF_MANTRA_DRAFT: "OEM_HANDOFF_MANTRA_DRAFT"
  };

  // src/lib/mantra-handoff-claim.js
  var MANTRA_CLAIM_MAX_WAIT_MS = 8e3;
  var MANTRA_CLAIM_INITIAL_DELAY_MS = 40;
  var MANTRA_CLAIM_MAX_DELAY_MS = 250;
  var RETRIABLE_HANDOFF_CLAIM_ERRORS = /* @__PURE__ */ new Set(["no-handoff"]);
  function isRetriableHandoffClaimError(error) {
    return typeof error === "string" && RETRIABLE_HANDOFF_CLAIM_ERRORS.has(error);
  }
  async function claimHandoffWithRetry(sendClaim2, options) {
    const oem = options.oem;
    const now = options.now ?? (() => Date.now());
    const sleep = options.sleep ?? ((ms) => new Promise((resolve) => {
      setTimeout(resolve, ms);
    }));
    const deadline = now() + (options.deadlineMs ?? MANTRA_CLAIM_MAX_WAIT_MS);
    let delay = MANTRA_CLAIM_INITIAL_DELAY_MS;
    while (now() < deadline) {
      const response = await sendClaim2({ type: MSG.HANDOFF_CLAIM, oem });
      if (response?.ok) {
        return response;
      }
      const error = response?.error ?? "handoff-failed";
      if (!isRetriableHandoffClaimError(error)) {
        return { ok: false, error };
      }
      const remaining = deadline - now();
      if (remaining <= 0) {
        break;
      }
      await sleep(Math.min(delay, remaining, MANTRA_CLAIM_MAX_DELAY_MS));
      delay = Math.min(delay * 1.5, MANTRA_CLAIM_MAX_DELAY_MS);
    }
    return { ok: false, error: "no-handoff" };
  }

  // src/lib/mantra-page-ui.js
  var MANTRA_STATUS_BANNER_ID = "radium-oem-handoff-banner";
  var BANNER_STYLE_BASE = "margin:8px 0;padding:8px;font-size:13px;border-radius:4px;";
  var TONE_STYLES = {
    success: {
      bg: "#d4edda",
      border: "#c3e6cb",
      text: "Serial number filled. Complete the CAPTCHA and click Check to view warranty details."
    },
    failure: {
      bg: "#fff3cd",
      border: "#ffeeba",
      text: "Serial could not be filled automatically. Enter the serial from Desk manually on this page."
    },
    timeout: {
      bg: "#f8d7da",
      border: "#f5c6cb",
      text: "Warranty form did not load correctly. Refresh this page or contact IT if it persists."
    }
  };
  function showMantraHandoffStatus(doc, tone) {
    if (doc.getElementById(MANTRA_STATUS_BANNER_ID)) {
      return;
    }
    const style = TONE_STYLES[tone];
    const el = doc.createElement("p");
    el.id = MANTRA_STATUS_BANNER_ID;
    el.setAttribute("role", "status");
    el.textContent = style.text;
    el.style.cssText = `${BANNER_STYLE_BASE}background:${style.bg};border:1px solid ${style.border};`;
    const anchor = doc.getElementById("txtSerialNo")?.parentElement ?? doc.body;
    anchor.prepend(el);
  }

  // src/lib/mantra-populate.js
  function populateMantraSerialField(doc, serial) {
    const el = doc.getElementById("txtSerialNo");
    if (!el || !(el instanceof HTMLInputElement)) {
      return { ok: false, reason: "txtSerialNo-missing" };
    }
    const normalized = String(serial).trim().toUpperCase();
    if (normalized === "") {
      return { ok: false, reason: "serial-empty" };
    }
    el.value = normalized;
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
    return { ok: true };
  }

  // src/lib/mantra-serial-field-wait.js
  var MANTRA_SERIAL_FIELD_MAX_WAIT_MS = 1e4;
  function waitForMantraSerialField(doc, selector, maxMs, hooks = {}) {
    const existing = doc.querySelector(selector);
    if (existing) {
      return Promise.resolve(existing);
    }
    const observeRoot = doc.body ?? doc.documentElement;
    if (!observeRoot) {
      return Promise.reject(new Error("timeout"));
    }
    const now = hooks.now ?? (() => Date.now());
    const deadline = now() + maxMs;
    return new Promise((resolve, reject) => {
      let observer = null;
      let timeoutId = null;
      let settled = false;
      const finish = (kind, value) => {
        if (settled) {
          return;
        }
        settled = true;
        observer?.disconnect();
        if (timeoutId != null) {
          clearTimeout(timeoutId);
        }
        if (kind === "resolve") {
          resolve(value);
        } else {
          reject(value);
        }
      };
      const tryResolve = () => {
        const el = doc.querySelector(selector);
        if (el) {
          finish("resolve", el);
          return true;
        }
        if (now() >= deadline) {
          finish("reject", new Error("timeout"));
          return true;
        }
        return false;
      };
      observer = hooks.observe ?? new MutationObserver(() => {
        tryResolve();
      });
      observer.observe(observeRoot, { childList: true, subtree: true });
      timeoutId = setTimeout(() => {
        tryResolve();
      }, Math.max(0, deadline - now()));
    });
  }
  function fillMantraSerialOnce(doc, serial) {
    const result = populateMantraSerialField(doc, serial);
    if (!result.ok) {
      return result;
    }
    const normalized = String(serial).trim().toUpperCase();
    const field = doc.getElementById("txtSerialNo");
    if (!(field instanceof HTMLInputElement) || field.value !== normalized) {
      return { ok: false, reason: "post-fill-verification-failed" };
    }
    return { ok: true };
  }

  // src/lib/mantra-result-parse.js
  function readMantraResultTable(doc) {
    const rows = [];
    const table = doc.querySelector("#TblRDWarrantyCheck tbody");
    if (!table) {
      return rows;
    }
    table.querySelectorAll("tr").forEach((tr) => {
      const cells = tr.querySelectorAll("td");
      if (cells.length < 4) {
        return;
      }
      rows.push({
        serial_number: cells[0]?.innerText?.trim() ?? "",
        warranty_expiry_date: cells[1]?.innerText?.trim() ?? "",
        rd_expiry_date: cells[2]?.innerText?.trim() ?? "",
        model: cells[3]?.innerText?.trim() ?? ""
      });
    });
    return rows;
  }
  function inferMantraOutcome(rows) {
    if (!rows.length) {
      return "inconclusive";
    }
    const primary = rows[0];
    const hasAnyValue = [primary.warranty_expiry_date, primary.rd_expiry_date, primary.model].some((value) => value && value !== "");
    return hasAnyValue ? "found" : "not_found";
  }
  function isMantraResultVisible(doc) {
    const rows = readMantraResultTable(doc);
    if (rows.length > 0) {
      return true;
    }
    const resultDiv = doc.querySelector("#divResult");
    return Boolean(resultDiv && resultDiv.style.display !== "none");
  }

  // src/content/mantra-fill.js
  var OEM = "mantra";
  var sendClaim = (payload) => new Promise((resolve) => {
    chrome.runtime.sendMessage(payload, (response) => {
      if (chrome.runtime.lastError) {
        resolve({ ok: false, error: "no-handoff" });
        return;
      }
      resolve(response ?? { ok: false, error: "handoff-failed" });
    });
  });
  var runMantraHandoff = async () => {
    const claim = await claimHandoffWithRetry(sendClaim, { oem: OEM });
    if (!claim?.ok || typeof claim.serial !== "string") {
      showMantraHandoffStatus(document, "failure");
      return;
    }
    try {
      await waitForMantraSerialField(document, "#txtSerialNo", MANTRA_SERIAL_FIELD_MAX_WAIT_MS);
    } catch {
      showMantraHandoffStatus(document, "timeout");
      return;
    }
    const fillResult = fillMantraSerialOnce(document, claim.serial);
    if (!fillResult.ok) {
      showMantraHandoffStatus(document, "failure");
      return;
    }
    showMantraHandoffStatus(document, "success");
    if (claim.mantra_capture_enabled !== true) {
      return;
    }
    let draftSent = false;
    const tryEmitDraft = () => {
      if (draftSent) {
        return;
      }
      const rows = readMantraResultTable(document);
      if (!rows.length || !isMantraResultVisible(document)) {
        return;
      }
      draftSent = true;
      observer.disconnect();
      chrome.runtime.sendMessage({
        type: MSG.HANDOFF_MANTRA_DRAFT,
        handoff_token: claim.handoff_token,
        incident_id: claim.incident_id,
        session_public_id: claim.session_public_id,
        outcome: inferMantraOutcome(rows),
        rows
      });
    };
    const observer = new MutationObserver(() => {
      tryEmitDraft();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    tryEmitDraft();
  };
  void runMantraHandoff();
})();
