import { chromium } from "playwright-core";
import { writeFileSync } from "node:fs";

const baseUrl = process.env.INVENTORY_POS_QA_BASE_URL;
const email = process.env.INVENTORY_POS_QA_EMAIL;
const password = process.env.INVENTORY_POS_QA_PASSWORD;
const reportPath = process.env.INVENTORY_POS_QA_REPORT || "/tmp/inventory-pos-browser-qa.json";
const chromePath =
  process.env.INVENTORY_POS_QA_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

if (!baseUrl || !email || !password) {
  console.error("INVENTORY_POS_QA_BASE_URL, INVENTORY_POS_QA_EMAIL, and INVENTORY_POS_QA_PASSWORD are required.");
  process.exit(2);
}

const parsed = new URL(baseUrl);
if (!["127.0.0.1", "localhost"].includes(parsed.hostname)) {
  console.error("Refusing non-local QA base URL.");
  process.exit(2);
}

const consoleErrors = [];
const pageErrors = [];
const failedRequests = [];
const notes = [];

function mustInclude(pageContent, snippet, label) {
  if (!pageContent.includes(snippet)) {
    throw new Error(`Missing expected copy for ${label}: ${snippet}`);
  }
  notes.push(`saw:${label}`);
}

async function selectByLabel(page, selector, text) {
  const value = await page.locator(`${selector} option`, { hasText: text }).first().getAttribute("value");
  if (!value) {
    throw new Error(`No option containing ${text} in ${selector}`);
  }
  await page.selectOption(selector, value);
  notes.push(`select:${selector}:${text}`);
}

const browser = await chromium.launch({
  executablePath: chromePath,
  headless: true,
  args: ["--disable-dev-shm-usage"],
});

const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
page.on("console", (msg) => {
  if (msg.type() !== "error") {
    return;
  }
  const location = msg.location();
  const detail = `${msg.text()} ${location.url || ""}`;
  if (/favicon\.ico/i.test(detail) || msg.text() === "Failed to load resource: the server responded with a status of 404 (Not Found)") {
    return;
  }
  consoleErrors.push(detail.trim());
});
page.on("response", (response) => {
  if (response.status() === 404 && !/\/favicon\.ico(\?|$)/i.test(response.url())) {
    failedRequests.push(`${response.status()} ${response.url()}`);
  }
});
page.on("pageerror", (error) => {
  pageErrors.push(error.message);
});
page.on("requestfailed", (request) => {
  failedRequests.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ""}`);
});

try {
  await page.goto(`${baseUrl}/login`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "Sign in to your account", "login");
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes("/login"), { timeout: 20000 });
  notes.push("login:ok");

  await page.goto(`${baseUrl}/inventory/products`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "MFS110-BROWSER", "product-search");

  await page.goto(`${baseUrl}/inventory/stock/in`, { waitUntil: "networkidle" });
  await selectByLabel(page, "#branch_id", "QAA");
  await selectByLabel(page, "#product_id", "MFS110-BROWSER");
  await page.fill("#serials", "QA-BR-001\nQA-BR-002\nQA-BR-HOLD");
  await page.locator('form button.btn-primary', { hasText: "Receive stock" }).click();
  await page.waitForURL((url) => url.pathname === "/inventory/stock" || url.pathname === "/inventory/stock/");
  notes.push("stock-in:serialized");

  await page.goto(`${baseUrl}/inventory/stock/in`, { waitUntil: "networkidle" });
  await selectByLabel(page, "#branch_id", "QAA");
  await selectByLabel(page, "#product_id", "OTG-BROWSER");
  await page.waitForFunction(() => [...document.querySelectorAll("#variant_id option")].some((option) => option.textContent.includes("OTG-BROWSER-1M")));
  await selectByLabel(page, "#variant_id", "OTG-BROWSER-1M");
  await page.fill("#qty", "5");
  await page.locator('form button.btn-primary', { hasText: "Receive stock" }).click();
  await page.waitForURL((url) => url.pathname === "/inventory/stock" || url.pathname === "/inventory/stock/");
  notes.push("stock-in:quantity");

  await page.goto(`${baseUrl}/inventory/serials`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "QA-BR-001", "serial-list");

  await page.goto(`${baseUrl}/inventory/transfers/create`, { waitUntil: "networkidle" });
  await selectByLabel(page, 'select[name="from_branch_id"]', "QAA");
  await selectByLabel(page, 'select[name="to_branch_id"]', "QAB");
  await page.fill('textarea[name="serials"]', "QA-BR-002");
  await page.locator('form button.btn-primary', { hasText: "Complete transfer" }).click();
  await page.waitForURL((url) => /\/inventory\/transfers\/\d+/.test(url.pathname) || url.pathname === "/inventory/transfers");
  notes.push("transfer:ok");

  await page.goto(`${baseUrl}/inventory/reservations/create`, { waitUntil: "networkidle" });
  await selectByLabel(page, 'select[name="branch_id"]', "QAA");
  await page.fill('textarea[name="serials"]', "QA-BR-HOLD");
  await page.locator('form button.btn-primary', { hasText: "Reserve" }).click();
  await page.waitForURL((url) => url.pathname === "/inventory/reservations" || url.pathname === "/inventory/reservations/");
  notes.push("reserve:ok");

  const release = page.locator('button:has-text("Release")').first();
  await release.click();
  await page.waitForURL((url) => url.pathname === "/inventory/reservations" || url.pathname === "/inventory/reservations/");
  notes.push("release:ok");

  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  if ((await page.content()).includes("Select a branch to start a sale")) {
    await selectByLabel(page, "#operating_branch_id", "QAA");
    await page.locator('form button', { hasText: "Switch branch" }).click();
    await page.waitForURL(/pos\/counter/);
  }
  mustInclude(await page.content(), "Selling from", "branch-banner");
  mustInclude(await page.content(), "QAA", "branch-context");

  await page.fill("#pos-product-search", "MFS110");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-results button");
  await page.click("#pos-serial-results button");
  notes.push("serial-select:ok");

  await page.fill("#pos-product-search", "OTG-BROWSER");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForTimeout(200);
  const qty = page.locator(".pos-qty").last();
  await qty.fill("2");
  await page.locator("#discount").fill("10");
  await page.locator("#discount").dispatchEvent("input");

  const subtotal = await page.locator("#pos-subtotal").innerText();
  const tax = await page.locator("#pos-tax").innerText();
  const total = await page.locator("#pos-total").innerText();
  notes.push(`totals:${subtotal}/${tax}/${total}`);
  if (subtotal !== "2580.00" || tax !== "464.40" || total !== "3034.40") {
    throw new Error(`Unexpected live totals ${subtotal} / ${tax} / ${total}`);
  }

  await page.fill("#customer_phone", "9111199001");
  await page.fill("#customer_name", "Browser QA Customer");
  await page.selectOption("#payment_method", "Cash");
  await page.click("#pos-complete");
  await page.waitForURL(/pos\/sales\/\d+/);
  mustInclude(await page.content(), "INV-QAA-", "invoice-number");
  if (!(await page.content()).includes("3034.40") && !(await page.content()).includes("3,034.40")) {
    throw new Error("Missing expected copy for completed-total: 3034.40");
  }
  notes.push("saw:completed-total");
  notes.push("sale:ok");

  await page.locator("a", { hasText: "Invoice" }).click();
  await page.waitForURL(/invoice/);
  mustInclude(await page.content(), "not a GST e-invoice", "invoice-disclaimer");
  notes.push("invoice:ok");

  await page.goto(`${baseUrl}/pos/sales`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "INV-QAA-", "sale-history");

  await page.locator("a", { hasText: "POS-" }).first().click();
  await page.waitForURL(/pos\/sales\/\d+/);
  await page.fill('input[name="reason"]', "Browser QA cancel restock");
  await page.locator('form button.btn-outline-danger', { hasText: "Cancel sale" }).click();
  await page.waitForURL(/pos\/sales\/\d+/);
  mustInclude(await page.content(), "Cancelled", "cancelled");
  notes.push("cancel:ok");

  await page.goto(`${baseUrl}/inventory/serials`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "QA-BR-001", "serial-after-cancel");

  const report = {
    ok: consoleErrors.length === 0 && pageErrors.length === 0 && failedRequests.length === 0,
    notes,
    consoleErrors,
    pageErrors,
    failedRequests,
  };
  writeFileSync(reportPath, JSON.stringify(report, null, 2));
  if (!report.ok) {
    console.error(JSON.stringify(report, null, 2));
    process.exit(1);
  }
  console.log(JSON.stringify({ ok: true, notes }, null, 2));
} catch (error) {
  await page.screenshot({ path: "/tmp/inventory-pos-browser-qa-fail.png", fullPage: true }).catch(() => {});
  writeFileSync(reportPath, JSON.stringify({
    ok: false,
    error: String(error),
    url: page.url(),
    notes,
    consoleErrors,
    pageErrors,
    failedRequests,
  }, null, 2));
  throw error;
} finally {
  await browser.close();
}
