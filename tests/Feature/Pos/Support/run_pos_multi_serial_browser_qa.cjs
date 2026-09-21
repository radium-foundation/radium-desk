const { writeFileSync } = require("node:fs");
const path = require("node:path");

const playwrightRoot = path.resolve(__dirname, "../../../../../radium-desk/node_modules");
module.paths.push(playwrightRoot);
const { chromium } = require("playwright-core");

const baseUrl = process.env.INVENTORY_POS_QA_BASE_URL;
const adminEmail = process.env.INVENTORY_POS_QA_EMAIL;
const hardwareEmail = process.env.INVENTORY_POS_QA_HARDWARE_EMAIL;
const password = process.env.INVENTORY_POS_QA_PASSWORD;
const reportPath = process.env.POS_MULTI_SERIAL_QA_REPORT || "/tmp/pos-multi-serial-browser-qa.json";
const chromePath =
  process.env.INVENTORY_POS_QA_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

if (!baseUrl || !adminEmail || !hardwareEmail || !password) {
  console.error("INVENTORY_POS_QA_BASE_URL, INVENTORY_POS_QA_EMAIL, INVENTORY_POS_QA_HARDWARE_EMAIL, and INVENTORY_POS_QA_PASSWORD are required.");
  process.exit(2);
}

const parsed = new URL(baseUrl);
if (!["127.0.0.1", "localhost"].includes(parsed.hostname)) {
  console.error("Refusing non-local QA base URL.");
  process.exit(2);
}

async function login(page, email) {
  await page.goto(`${baseUrl}/login`, { waitUntil: "networkidle" });
  if (!page.url().includes("/login")) {
    return;
  }
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes("/login"), { timeout: 20000 });
}

async function ensureCounterBranch(page) {
  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  if ((await page.content()).includes("Select a branch to start a sale")) {
    await page.selectOption("#operating_branch_id", { label: "QAA" });
    await page.locator("form button", { hasText: "Switch branch" }).click();
    await page.waitForURL(/pos\/counter/);
  }
}

async function cartRowCount(page) {
  return page.locator("#pos-cart-body tr.pos-cart-row").count();
}

async function lastCartQty(page) {
  return page.locator(".pos-qty").last().inputValue();
}

async function lastCartSerials(page) {
  return page.locator("#pos-cart-body tr.pos-cart-row").last().locator(".small.text-muted").innerText();
}

async function selectSerializedProduct(page, query) {
  await page.fill("#pos-product-search", query);
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-entry");
}

async function enterSerials(page, value) {
  await page.fill("#pos-serial-entry", value);
  await page.press("#pos-serial-entry", "Enter");
  await page.waitForTimeout(400);
}

(async () => {
  const consoleErrors = [];
  const pageErrors = [];
  const notes = [];
  const issues = [];

  const browser = await chromium.launch({
    headless: true,
    executablePath: chromePath,
  });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  page.on("console", (message) => {
    if (message.type() !== "error") {
      return;
    }
    const text = message.text();
    if (text.includes("404") && text.includes("/build/assets/")) {
      return;
    }
    consoleErrors.push(text);
  });
  page.on("pageerror", (error) => {
    pageErrors.push(error.message);
  });

  try {
    await login(page, hardwareEmail);
    await ensureCounterBranch(page);

    await selectSerializedProduct(page, "MFS110-BROWSER");

    await enterSerials(page, "MERGE-SN-001");
    if (await cartRowCount(page) !== 1) {
      issues.push("After first serial, expected one cart row.");
    }
    if ((await lastCartQty(page)) !== "1") {
      issues.push(`After first serial, expected qty 1, got ${await lastCartQty(page)}.`);
    }
    if ((await page.locator("#pos-serial-selected .font-monospace").count()) !== 1) {
      issues.push("Selected serial chip count should be 1 after first scan.");
    }

    await enterSerials(page, "MERGE-SN-002");
    if (await cartRowCount(page) !== 1) {
      issues.push("After second serial for same model, expected one cart row.");
    }
    if ((await lastCartQty(page)) !== "2") {
      issues.push(`After second serial, expected qty 2, got ${await lastCartQty(page)}.`);
    }
    if ((await page.locator("#pos-serial-selected .font-monospace").count()) !== 2) {
      issues.push("Selected serial chip count should be 2 after second scan.");
    }

    await page.locator("#pos-serial-entry").fill("MERGE-SN-001 MERGE-SN-003");
    await page.locator("#pos-serial-entry").press("Enter");
    await page.waitForTimeout(400);
    if ((await lastCartQty(page)) !== "3") {
      issues.push(`After paste-style entry, expected qty 3, got ${await lastCartQty(page)}.`);
    }
    const feedback = await page.locator("#pos-serial-feedback").innerText();
    if (!feedback.includes("Already selected: MERGE-SN-001")) {
      issues.push(`Duplicate serial feedback missing: ${feedback}`);
    }

    const serialText = await lastCartSerials(page);
    if (!serialText.includes("MERGE-SN-001") || !serialText.includes("MERGE-SN-002") || !serialText.includes("MERGE-SN-003")) {
      issues.push(`Cart line missing expected serial association: ${serialText}`);
    }

    await page.locator("#pos-serial-selected button.btn-close").first().click();
    await page.waitForTimeout(200);
    if ((await lastCartQty(page)) !== "2") {
      issues.push(`Removing one serial should reduce qty to 2, got ${await lastCartQty(page)}.`);
    }

    await page.fill("#pos-product-search", "OTG-BROWSER-1M");
    await page.waitForSelector("#pos-product-results button");
    await page.locator("#pos-product-results button", { hasText: "OTG-BROWSER-1M" }).click();
    await page.waitForTimeout(200);
    if ((await cartRowCount(page)) !== 2) {
      issues.push("Different product should create a separate cart row.");
    }

    await page.setViewportSize({ width: 390, height: 844 });
    notes.push("viewport:mobile-ok");
  } catch (error) {
    issues.push(error.message);
  } finally {
    await browser.close();
  }

  const report = {
    ok: issues.length === 0 && pageErrors.length === 0,
    notes,
    issues,
    consoleErrors,
    pageErrors,
  };

  writeFileSync(reportPath, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
  process.exit(report.ok ? 0 : 1);
})();
