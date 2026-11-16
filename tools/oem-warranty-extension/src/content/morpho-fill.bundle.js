(() => {
  // src/lib/constants.js
  var MSG = {
    HANDOFF_REQUEST: "OEM_HANDOFF_REQUEST",
    HANDOFF_CLAIM: "OEM_HANDOFF_CLAIM",
    HANDOFF_CLAIM_RESULT: "OEM_HANDOFF_CLAIM_RESULT",
    HANDOFF_MANTRA_DRAFT: "OEM_HANDOFF_MANTRA_DRAFT",
    HANDOFF_OEM_RESULT: "OEM_HANDOFF_OEM_RESULT"
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

  // src/lib/morpho-populate.js
  function populateMorphoSerialFieldDomOnly(doc, serial) {
    const base = doc.getElementById("device-serial-number1");
    const visible = doc.getElementById("device-serial-number1-selectized");
    if (!base || !(base instanceof HTMLInputElement)) {
      return { ok: false, reason: "device-serial-number1-missing" };
    }
    const normalized = String(serial).trim().toUpperCase();
    if (normalized === "") {
      return { ok: false, reason: "serial-empty" };
    }
    base.value = normalized;
    base.dispatchEvent(new Event("input", { bubbles: true }));
    if (visible instanceof HTMLInputElement) {
      visible.value = normalized;
    }
    return { ok: true, mode: "dom-only" };
  }

  // src/lib/morpho-result-parse.js
  function readMorphoResultFields(doc) {
    const panel = doc.querySelector("#morpho-validation-panel, #divValidationResult, .validation-result-panel");
    const serial = doc.querySelector("#lblSerial, [data-oem-serial], #device-serial-number1")?.value ?? doc.querySelector("#lblSerial, [data-oem-serial]")?.textContent?.trim() ?? "";
    const readCell = (selectors) => {
      for (const selector of selectors) {
        const el = doc.querySelector(selector);
        if (!el) {
          continue;
        }
        const text = el instanceof HTMLInputElement ? el.value : el.textContent;
        const value = text?.trim() ?? "";
        if (value !== "") {
          return value;
        }
      }
      return "";
    };
    return {
      serial_number: serial,
      device_validity: readCell(["#lblDeviceValidity", "[data-device-validity]", ".device-validity"]),
      whitelist_status: readCell(["#lblWhitelistStatus", "[data-whitelist-status]", ".whitelist-status"]),
      whitelist_date: readCell(["#lblWhitelistDate", "[data-whitelist-date]"]),
      expiry_date: readCell(["#lblExpiryDate", "[data-expiry-date]", ".expiry-date"]),
      validity_years: readCell(["#lblValidityYears", "[data-validity-years]"]),
      device_type: readCell(["#lblDeviceType", "[data-device-type]", ".device-type"]),
      panel_visible: Boolean(panel && panel instanceof HTMLElement && panel.offsetParent !== null)
    };
  }
  function isMorphoResultVisible(doc) {
    const fields = readMorphoResultFields(doc);
    if (!fields.panel_visible) {
      const tbody = doc.querySelector("#tblDeviceResult tbody, table.device-result tbody");
      if (!tbody || tbody.querySelectorAll("tr").length === 0) {
        return false;
      }
    }
    return [fields.device_validity, fields.whitelist_status, fields.expiry_date, fields.device_type].some((value) => value && value !== "");
  }
  function inferMorphoOutcome(fields) {
    const hasData = [fields.device_validity, fields.whitelist_status, fields.expiry_date, fields.device_type].some((value) => value && value !== "");
    if (!hasData) {
      return "inconclusive";
    }
    const validity = (fields.device_validity ?? "").toLowerCase();
    if (validity.includes("invalid") || validity.includes("not valid")) {
      return "not_found";
    }
    return "found";
  }

  // src/lib/oem-result-observe.js
  var OEM_RESULT_OBSERVE_MAX_MS = 6e5;
  function observeOemResult(doc, options) {
    const maxMs = options.maxObserveMs ?? OEM_RESULT_OBSERVE_MAX_MS;
    let emitted = false;
    const tryEmit = () => {
      if (emitted || !options.isReady(doc)) {
        return;
      }
      const payload = options.buildPayload(doc);
      if (!payload) {
        return;
      }
      emitted = true;
      observer.disconnect();
      clearTimeout(timeoutId);
      options.onEmit(payload);
    };
    const observer = new MutationObserver(() => {
      tryEmit();
    });
    observer.observe(doc.body, { childList: true, subtree: true });
    const timeoutId = window.setTimeout(() => {
      observer.disconnect();
    }, maxMs);
    tryEmit();
    return () => {
      emitted = true;
      observer.disconnect();
      clearTimeout(timeoutId);
    };
  }

  // src/content/morpho-fill.js
  var OEM = "morpho";
  var sendClaim = (payload) => new Promise((resolve) => {
    chrome.runtime.sendMessage(payload, (response) => {
      if (chrome.runtime.lastError) {
        resolve({ ok: false, error: "no-handoff" });
        return;
      }
      resolve(response ?? { ok: false, error: "handoff-failed" });
    });
  });
  var showBanner = (message, tone = "info") => {
    const bannerId = "radium-oem-handoff-banner";
    if (document.getElementById(bannerId)) {
      return;
    }
    const el = document.createElement("p");
    el.id = bannerId;
    el.setAttribute("role", "status");
    el.textContent = message;
    const bg = tone === "warn" ? "#fff3cd" : "#e7f3ff";
    const border = tone === "warn" ? "#ffeeba" : "#b8daff";
    el.style.cssText = `margin:8px 0;padding:8px;background:${bg};border:1px solid ${border};font-size:13px;`;
    const anchor = document.getElementById("device-serial-number1");
    anchor?.parentElement?.prepend(el);
  };
  var emitMorphoResult = (claim, fields, outcome) => {
    chrome.runtime.sendMessage({
      type: MSG.HANDOFF_OEM_RESULT,
      oem: OEM,
      handoff_token: claim.handoff_token,
      incident_id: claim.incident_id,
      session_public_id: claim.session_public_id,
      outcome,
      fields
    });
  };
  var runMorphoHandoff = async () => {
    const claim = await claimHandoffWithRetry(sendClaim, { oem: OEM });
    if (!claim?.ok || typeof claim.serial !== "string") {
      showBanner("Serial handoff was not available. Enter the serial manually.", "warn");
      return;
    }
    const fill = populateMorphoSerialFieldDomOnly(document, claim.serial);
    if (!fill.ok) {
      showBanner("Serial could not be filled automatically. Enter it manually.", "warn");
      return;
    }
    showBanner("Serial prefilled (visual only). Complete OEM validation manually, then review results in Desk.");
    const captureEnabled = claim.result_capture_enabled === true || claim.morpho_capture_enabled === true;
    if (!captureEnabled) {
      return;
    }
    observeOemResult(document, {
      isReady: isMorphoResultVisible,
      buildPayload: (doc) => {
        const fields = readMorphoResultFields(doc);
        return {
          outcome: inferMorphoOutcome(fields),
          fields
        };
      },
      onEmit: ({ outcome, fields }) => {
        showBanner("OEM result captured for Desk review. Return to Desk to confirm or reject.");
        emitMorphoResult(claim, fields, outcome);
      }
    });
  };
  void runMorphoHandoff();
})();
