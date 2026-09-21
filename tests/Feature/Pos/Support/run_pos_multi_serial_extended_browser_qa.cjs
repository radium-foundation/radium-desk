const { writeFileSync } = require("node:fs");
const path = require("node:path");

const playwrightRoot = path.resolve(__dirname, "../../../../../radium-desk/node_modules");
module.paths.push(playwrightRoot);
const { chromium } = require("playwright-core");

const baseUrl = process.env.INVENTORY_POS_QA_BASE_URL || "http://127.0.0.1:8899";
const hardwareEmail = process.env.INVENTORY_POS_QA_HARDWARE_EMAIL || "qa-inventory-pos-hardware@radium.local";
const password = process.env.INVENTORY_POS_QA_PASSWORD || "password";
const chromePath =
  process.env.INVENTORY_POS_QA_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

async function login(page) {
  await page.goto(`${baseUrl}/login`, { waitUntil: "networkidle" });
  if (page.url().includes("/login")) {
    await page.fill("#email", hardwareEmail);
    await page.fill("#password", password);
    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes("/login"), { timeout: 20000 });
  }
}

async function ensureCounterBranch(page) {
  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  if ((await page.content()).includes("Select a branch to start a sale")) {
    await page.selectOption("#operating_branch_id", { label: "QAA" });
    await page.locator("form button", { hasText: "Switch branch" }).click();
    await page.waitForURL(/pos\/counter/);
  }
}

async function selectSerializedProduct(page) {
  await page.fill("#pos-product-search", "MFS110-BROWSER");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-entry");
}

async function clearCart(page) {
  while ((await page.locator(".pos-remove").count()) > 0) {
    await page.locator(".pos-remove").first().click();
    await page.waitForTimeout(150);
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chromePath });
  const page = await browser.newPage();
  const results = {};
  const issues = [];

  try {
    await login(page);
    await ensureCounterBranch(page);
    await selectSerializedProduct(page);

    await clearCart(page);
    await page.fill("#pos-serial-entry", "MERGE-SN-001");
    await page.press("#pos-serial-entry", "Enter");
    await page.waitForTimeout(300);
    results.A_single_serial = (await page.locator(".pos-qty").inputValue()) === "1";

    await clearCart(page);
    await page.fill("#pos-serial-entry", "MERGE-SN-001,MERGE-SN-002,MERGE-SN-003");
    await page.press("#pos-serial-entry", "Enter");
    await page.waitForTimeout(400);
    results.D_comma_paste = (await page.locator(".pos-qty").inputValue()) === "3";

    await clearCart(page);
    await page.fill("#pos-serial-entry", "MERGE-SN-001\nMERGE-SN-002\nMERGE-SN-003");
    await page.press("#pos-serial-entry", "Enter");
    await page.waitForTimeout(400);
    results.E_newline_paste = (await page.locator(".pos-qty").inputValue()) === "3";

    await clearCart(page);
    await page.fill("#pos-serial-entry", "MERGE-SN-001 MERGE-SN-999");
    await page.press("#pos-serial-entry", "Enter");
    await page.waitForTimeout(400);
    const feedback = await page.locator("#pos-serial-feedback").innerText();
    results.G_invalid_serial = (await page.locator(".pos-qty").inputValue()) === "1"
      && feedback.includes("Not found for this product: MERGE-SN-999");

    await page.fill("#pos-serial-entry", "MERGE-SN-001");
    await page.press("#pos-serial-entry", "Enter");
    await page.waitForTimeout(200);
    const hidden = await page.locator('#pos-cart-fields input[name="lines[0][serials]"]').inputValue();
    results.I_form_serials_ready = hidden.split("\n").filter(Boolean).length >= 1;

    Object.entries(results).forEach(([key, ok]) => {
      if (!ok) {
        issues.push(`${key} failed`);
      }
    });
  } catch (error) {
    issues.push(error.message);
  } finally {
    await browser.close();
  }

  const report = { ok: issues.length === 0, results, issues };
  writeFileSync("/tmp/pos-multi-serial-extended-browser-qa.json", JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
  process.exit(report.ok ? 0 : 1);
})();
