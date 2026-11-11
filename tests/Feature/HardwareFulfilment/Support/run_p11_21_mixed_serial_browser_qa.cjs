const fs = require("node:fs");
const path = require("node:path");

const playwrightRoot = path.resolve(__dirname, "../../../../node_modules");
module.paths.push(playwrightRoot);
const { chromium } = require("playwright-core");

const root = path.resolve(__dirname, "../../../..");
const credPath = path.join(root, "storage/app/private/qa-evidence/p11-21/browser-credentials.json");
const creds = JSON.parse(fs.readFileSync(credPath, "utf8"));
const evidenceDir = path.join(root, "storage/app/private/qa-evidence/p11-21");
const shotsDir = path.join(evidenceDir, "screenshots");
const reportPath = path.join(evidenceDir, "mixed-serial-browser-qa.json");
const chromePath =
  process.env.INVENTORY_POS_QA_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

fs.mkdirSync(shotsDir, { recursive: true });

const baseUrl = creds.base_url;
const parsed = new URL(baseUrl);
if (!["127.0.0.1", "localhost"].includes(parsed.hostname)) {
  console.error("Refusing non-local QA base URL.");
  process.exit(2);
}

async function login(page) {
  await page.goto(`${baseUrl}/login`, { waitUntil: "domcontentloaded" });
  await page.fill("#email", creds.email);
  await page.fill("#password", creds.password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes("/login"), { timeout: 30000 });
}

async function openShow(page, fulfilmentId) {
  return page.goto(`${baseUrl}/inventory/hardware-fulfilments/${fulfilmentId}`, {
    waitUntil: "domcontentloaded",
  });
}

async function searchSerial(page, serial) {
  const input = page.locator(".js-serial-query").first();
  await input.fill(serial);
  await page.waitForTimeout(350);
}

(async () => {
  const checks = [];
  const consoleErrors = [];
  const failedRequests = [];

  const browser = await chromium.launch({ headless: true, executablePath: chromePath });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.on("console", (msg) => {
    if (msg.type() === "error") consoleErrors.push(msg.text());
  });
  page.on("requestfailed", (req) => failedRequests.push(req.url()));

  try {
    await login(page);

    // Cable-only: quantity form, no serial picker
    await openShow(page, creds.fulfilments.cable_only);
    const cableHtml = await page.content();
    const cableQtyForm = await page.locator("#hardware-quantity-allocate-form").count();
    const cableSerialForm = await page.locator("#hardware-serial-allocate-form").count();
    checks.push({
      id: "cable_only_quantity_allocation",
      pass: cableQtyForm === 1 && cableSerialForm === 0 && cableHtml.includes("no serial numbers are required"),
      cableQtyForm,
      cableSerialForm,
    });
    await page.screenshot({ path: path.join(shotsDir, "01-cable-only-show.png"), fullPage: true });
    page.once("dialog", (d) => d.accept());
    await page.click('#hardware-quantity-allocate-form button[type="submit"]');
    await page.waitForLoadState("networkidle", { timeout: 20000 }).catch(() => {});
    checks.push({
      id: "cable_only_allocate_success",
      pass: (await page.content()).toLowerCase().includes("serials allocated") || (await page.content()).includes("Serials allocated"),
    });
    await page.screenshot({ path: path.join(shotsDir, "02-cable-after-allocate.png"), fullPage: true });

    // Scanner-only: one serial required
    await openShow(page, creds.fulfilments.scanner_only);
  const scanPickers = await page.locator("[data-picker-for]").count();
    checks.push({
      id: "scanner_only_one_picker",
      pass: scanPickers === 1,
      scanPickers,
    });
    await page.screenshot({ path: path.join(shotsDir, "03-scanner-only-show.png"), fullPage: true });

    // Mixed: one serial picker + cable lines without picker
    await openShow(page, creds.fulfilments.mixed);
    const mixPickers = await page.locator("[data-picker-for]").count();
    const mixHtml = await page.content();
    checks.push({
      id: "mixed_one_serial_picker",
      pass: mixPickers === 1 && mixHtml.includes("quantity-tracked") === false,
      mixPickers,
    });
    await page.screenshot({ path: path.join(shotsDir, "04-mixed-show.png"), fullPage: true });

    // Valid serial on mixed
    await searchSerial(page, "SN-P21-OK");
    await page.locator(".hf-alloc-serial").first().waitFor({ state: "visible", timeout: 15000 });
    await page.locator(".hf-alloc-serial").first().click();
    await page.waitForFunction(() => {
      const btn = document.getElementById("hardware-serial-submit");
      return btn && !btn.disabled;
    });
    page.once("dialog", (d) => d.accept());
    await page.click("#hardware-serial-submit");
    await page.waitForLoadState("networkidle", { timeout: 20000 }).catch(() => {});
    checks.push({
      id: "mixed_valid_serial_allocate",
      pass: (await page.content()).includes("SN-P21-OK"),
    });
    await page.screenshot({ path: path.join(shotsDir, "05-mixed-after-allocate.png"), fullPage: true });

    // Duplicate serial rejected on second scanner order
    await openShow(page, creds.fulfilments.scanner_second);
    await searchSerial(page, "SN-P21-OK");
    await page.waitForTimeout(400);
    const dupText = await page.locator(".js-serial-results").first().innerText();
    checks.push({
      id: "duplicate_serial_rejected",
      pass: dupText.toLowerCase().includes("no available") || !/\bSN-P21-OK\b/.test(dupText),
      dupText: dupText.slice(0, 160),
    });

    // Invalid serial
    await openShow(page, creds.fulfilments.scanner_only);
    await searchSerial(page, "SN-DOES-NOT-EXIST-1121");
    await page.waitForTimeout(400);
    const invalidText = await page.locator(".js-serial-results").first().innerText();
    checks.push({
      id: "invalid_serial_rejected",
      pass: invalidText.toLowerCase().includes("no available"),
      invalidText: invalidText.slice(0, 160),
    });
    await page.screenshot({ path: path.join(shotsDir, "06-invalid-serial.png"), fullPage: true });

    // Wrong-branch serial (Mumbai stock, Delhi fulfilment)
    await searchSerial(page, "SN-P21-WRONG-BR");
    await page.waitForTimeout(400);
    const wrongText = await page.locator(".js-serial-results").first().innerText();
    const wrongButtons = await page.locator(".hf-alloc-serial").count();
    checks.push({
      id: "wrong_branch_serial_rejected",
      pass: wrongButtons === 0 || wrongText.toLowerCase().includes("no available"),
      wrongText: wrongText.slice(0, 160),
      wrongButtons,
    });
    await page.screenshot({ path: path.join(shotsDir, "07-wrong-branch-serial.png"), fullPage: true });

    // Idempotency / retry: allocate scanner-only with SN-P21-DUP
    await openShow(page, creds.fulfilments.scanner_only);
    await searchSerial(page, "SN-P21-DUP");
    await page.locator(".hf-alloc-serial").first().waitFor({ state: "visible", timeout: 15000 });
    await page.locator(".hf-alloc-serial").first().click();
    await page.waitForFunction(() => {
      const btn = document.getElementById("hardware-serial-submit");
      return btn && !btn.disabled;
    });
    page.once("dialog", (d) => d.accept());
    await page.click("#hardware-serial-submit");
    await page.waitForLoadState("networkidle", { timeout: 20000 }).catch(() => {});
    const afterScan = await page.content();
    checks.push({
      id: "scanner_allocate_success",
      pass: afterScan.includes("SN-P21-DUP") || afterScan.toLowerCase().includes("allocated"),
    });
    await page.screenshot({ path: path.join(shotsDir, "08-scanner-after-allocate.png"), fullPage: true });
  } finally {
    await browser.close();
  }

  const report = {
    prompt: "P-11-21",
    base_url: baseUrl,
    finished_at: new Date().toISOString(),
    checks,
    console_errors: consoleErrors.filter((e) => !e.includes("/build/assets/")),
    failed_requests: failedRequests,
    screenshots_dir: shotsDir,
  };
  fs.writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`);
  const failed = checks.filter((c) => !c.pass);
  console.log(JSON.stringify({ pass: failed.length === 0, failed: failed.map((f) => f.id), reportPath }));
  process.exit(failed.length === 0 ? 0 : 1);
})();
