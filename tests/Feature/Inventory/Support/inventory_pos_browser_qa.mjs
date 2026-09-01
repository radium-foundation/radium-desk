import { chromium } from "playwright-core";
import { writeFileSync } from "node:fs";

const baseUrl = process.env.INVENTORY_POS_QA_BASE_URL;
const adminEmail = process.env.INVENTORY_POS_QA_EMAIL;
const password = process.env.INVENTORY_POS_QA_PASSWORD;
const hardwareEmail = process.env.INVENTORY_POS_QA_HARDWARE_EMAIL;
const unassignedEmail = process.env.INVENTORY_POS_QA_UNASSIGNED_EMAIL;
const agentEmail = process.env.INVENTORY_POS_QA_AGENT_EMAIL;
const reportPath = process.env.INVENTORY_POS_QA_REPORT || "/tmp/inventory-pos-browser-qa.json";
const chromePath =
  process.env.INVENTORY_POS_QA_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

if (!baseUrl || !adminEmail || !password) {
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
const issues = [];

function mustInclude(pageContent, snippet, label) {
  if (!pageContent.includes(snippet)) {
    throw new Error(`Missing expected copy for ${label}: ${snippet}`);
  }
  notes.push(`saw:${label}`);
}

function mustNotInclude(pageContent, snippet, label) {
  if (pageContent.includes(snippet)) {
    throw new Error(`Unexpected copy for ${label}: ${snippet}`);
  }
  notes.push(`absent:${label}`);
}

async function selectByLabel(page, selector, text) {
  const value = await page.locator(`${selector} option`, { hasText: text }).first().getAttribute("value");
  if (!value) {
    throw new Error(`No option containing ${text} in ${selector}`);
  }
  await page.selectOption(selector, value);
  notes.push(`select:${selector}:${text}`);
}

async function login(page, email) {
  await page.goto(`${baseUrl}/login`, { waitUntil: "networkidle" });
  if (!page.url().includes("/login")) {
    notes.push(`login:already-authenticated:${email}`);
    return;
  }
  mustInclude(await page.content(), "Sign in to your account", "login");
  await page.fill("#email", email);
  await page.fill("#password", password);
  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes("/login"), { timeout: 20000 });
  notes.push(`login:ok:${email}`);
}

async function expandSidebar(page) {
  const expanded = await page.evaluate(() => document.documentElement.classList.contains("sidebar-expanded"));
  if (!expanded) {
    await page.locator("[data-sidebar-toggle]").click();
    await page.waitForFunction(() => document.documentElement.classList.contains("sidebar-expanded"));
  }
  notes.push("sidebar:expanded");
}

async function logout(page) {
  await page.goto(`${baseUrl}/dashboard`, { waitUntil: "domcontentloaded" });
  const token = await page.locator('meta[name="csrf-token"]').getAttribute("content");
  await page.evaluate(async (csrf) => {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "/logout";
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = "_token";
    input.value = csrf;
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
  }, token);
  await page.waitForURL(/\/login/, { timeout: 20000 });
  notes.push("logout:ok");
}

async function statusOf(page, path) {
  const response = await page.goto(`${baseUrl}${path}`, { waitUntil: "domcontentloaded" });
  return response?.status() ?? 0;
}

const browser = await chromium.launch({
  executablePath: chromePath,
  headless: true,
  args: ["--disable-dev-shm-usage"],
});

const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
function ignorableConsole(detail) {
  return /favicon\.ico/i.test(detail)
    || /\/presence\/heartbeat/i.test(detail)
    || /\/notifications\/poll/i.test(detail)
    || /status of 429 \(Too Many Requests\)/i.test(detail)
    || /status of 403 \(Forbidden\)/i.test(detail);
}

page.on("console", (msg) => {
  if (msg.type() !== "error") {
    return;
  }
  const location = msg.location();
  const detail = `${msg.text()} ${location.url || ""}`;
  if (ignorableConsole(detail) || msg.text() === "Failed to load resource: the server responded with a status of 404 (Not Found)") {
    return;
  }
  consoleErrors.push(detail.trim());
});
page.on("response", (response) => {
  const url = response.url();
  if (response.status() === 404 && !/\/favicon\.ico(\?|$)/i.test(url) && !url.includes("/notifications/poll") && !url.includes("/presence/heartbeat")) {
    failedRequests.push(`${response.status()} ${url}`);
  }
});
page.on("pageerror", (error) => {
  pageErrors.push(error.message);
});
page.on("requestfailed", (request) => {
  const url = request.url();
  if (/favicon\.ico/i.test(url) || url.includes("/notifications/poll") || url.includes("/presence/heartbeat")) {
    return;
  }
  failedRequests.push(`${request.method()} ${url} ${request.failure()?.errorText || ""}`);
});

let completePosts = 0;
page.on("request", (request) => {
  if (request.method() !== "POST") {
    return;
  }
  try {
    const url = new URL(request.url());
    if (url.pathname === "/pos/counter" || url.pathname === "/pos/counter/") {
      completePosts += 1;
    }
  } catch {
    // ignore
  }
});

try {
  await login(page, adminEmail);

  await page.goto(`${baseUrl}/dashboard`, { waitUntil: "networkidle" });
  const dashboard = await page.content();
  mustInclude(dashboard, "Inventory", "sidebar-inventory");
  mustInclude(dashboard, "POS", "sidebar-pos");
  await expandSidebar(page);
  await page.locator("a.nav-section-link", { hasText: "Inventory" }).first().click();
  await page.waitForURL(/\/inventory\//);
  notes.push("nav:inventory");

  await page.goto(`${baseUrl}/inventory/products`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "MFS110-BROWSER", "product-search");

  await page.locator("a", { hasText: "Stock in" }).first().click().catch(async () => {
    await page.goto(`${baseUrl}/inventory/stock/in`, { waitUntil: "networkidle" });
  });
  await page.goto(`${baseUrl}/inventory/stock/in`, { waitUntil: "networkidle" });
  await selectByLabel(page, "#branch_id", "QAA");
  await selectByLabel(page, "#product_id", "MFS110-BROWSER");
  await page.fill("#serials", "QA-BR-001\nQA-BR-002\nQA-BR-HOLD\nQA-HW-001");
  await page.locator("form button.btn-primary", { hasText: "Receive stock" }).click();
  await page.waitForURL((url) => url.pathname === "/inventory/stock" || url.pathname === "/inventory/stock/");
  notes.push("stock-in:serialized");

  await page.goto(`${baseUrl}/inventory/stock/in`, { waitUntil: "networkidle" });
  await selectByLabel(page, "#branch_id", "QAA");
  await selectByLabel(page, "#product_id", "MFS110-BROWSER");
  await page.fill("#serials", "QA-BR-001");
  await page.locator("form button.btn-primary", { hasText: "Receive stock" }).click();
  await page.waitForSelector(".alert-danger");
  mustInclude(await page.content(), "already exists", "duplicate-serial-error");
  notes.push("error:duplicate-serial");

  await page.goto(`${baseUrl}/inventory/stock/in`, { waitUntil: "networkidle" });
  await selectByLabel(page, "#branch_id", "QAA");
  await selectByLabel(page, "#product_id", "OTG-BROWSER");
  await page.waitForFunction(() => [...document.querySelectorAll("#variant_id option")].some((option) => option.textContent.includes("OTG-BROWSER-1M")));
  await page.locator("form button.btn-primary", { hasText: "Receive stock" }).click();
  const variantBlocked = await page.locator("#variant_id:invalid").count();
  if (variantBlocked > 0) {
    notes.push("error:variant-required-html5");
  } else {
    await page.waitForSelector(".alert-danger");
    mustInclude(await page.content(), "Select a variant", "variant-required-error");
    notes.push("error:variant-required");
  }

  await selectByLabel(page, "#variant_id", "OTG-BROWSER-1M");
  await page.fill("#qty", "5");
  await page.locator("form button.btn-primary", { hasText: "Receive stock" }).click();
  await page.waitForURL((url) => url.pathname === "/inventory/stock" || url.pathname === "/inventory/stock/");
  notes.push("stock-in:quantity");

  await page.goto(`${baseUrl}/inventory/serials`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "QA-BR-001", "serial-list");
  const serialSearch = page.locator('input[name="q"], input[type="search"]').first();
  if (await serialSearch.count()) {
    await serialSearch.fill("QA-BR-HOLD");
    const serialFilter = page.locator('form button, form input[type="submit"]').first();
    if (await serialFilter.count()) {
      await serialFilter.click();
      await page.waitForLoadState("networkidle");
    }
  }

  await page.goto(`${baseUrl}/inventory/transfers/create`, { waitUntil: "networkidle" });
  await selectByLabel(page, 'select[name="from_branch_id"]', "QAA");
  await selectByLabel(page, 'select[name="to_branch_id"]', "QAB");
  await page.fill('textarea[name="serials"]', "QA-BR-002");
  await page.locator("form button.btn-primary", { hasText: "Complete transfer" }).click();
  await page.waitForURL((url) => /\/inventory\/transfers\/\d+/.test(url.pathname) || url.pathname === "/inventory/transfers");
  notes.push("transfer:ok");

  await page.goto(`${baseUrl}/inventory/reservations/create`, { waitUntil: "networkidle" });
  await selectByLabel(page, 'select[name="branch_id"]', "QAA");
  await page.fill('textarea[name="serials"]', "QA-BR-HOLD");
  await page.locator("form button.btn-primary", { hasText: "Reserve" }).click();
  await page.waitForURL((url) => url.pathname === "/inventory/reservations" || url.pathname === "/inventory/reservations/");
  notes.push("reserve:ok");

  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  if ((await page.content()).includes("Select a branch to start a sale")) {
    await selectByLabel(page, "#operating_branch_id", "QAA");
    await page.locator("form button", { hasText: "Switch branch" }).click();
    await page.waitForURL(/pos\/counter/);
  }
  mustInclude(await page.content(), "Selling from", "branch-banner");
  mustInclude(await page.content(), "QAA", "branch-context");

  await page.fill("#pos-product-search", "MFS110");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-results button, #pos-serial-results .list-group-item");
  const reservedVisible = await page.locator("#pos-serial-results button", { hasText: "QA-BR-HOLD" }).count();
  if (reservedVisible > 0) {
    issues.push("Reserved serial QA-BR-HOLD appeared in POS pick list.");
  } else {
    notes.push("serial-search:reserved-hidden");
  }
  const transferredVisible = await page.locator("#pos-serial-results button", { hasText: "QA-BR-002" }).count();
  if (transferredVisible > 0) {
    issues.push("Transferred serial QA-BR-002 still appeared at QAA.");
  } else {
    notes.push("serial-search:transferred-hidden");
  }
  await page.fill("#pos-serial-search", "QA-BR-001");
  await page.waitForTimeout(400);
  await page.waitForSelector("#pos-serial-results button");
  await page.locator("#pos-serial-results button", { hasText: "QA-BR-001" }).click();
  notes.push("serial-select:ok");

  await page.goto(`${baseUrl}/inventory/reservations`, { waitUntil: "networkidle" });
  const release = page.locator('button:has-text("Release")').first();
  await release.click();
  await page.waitForURL((url) => url.pathname === "/inventory/reservations" || url.pathname === "/inventory/reservations/");
  notes.push("release:ok");

  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  await expandSidebar(page);
  await page.locator("a[data-nav-key=\"pos.counter\"]").click();
  await page.waitForURL(/pos\/counter/);
  mustInclude(await page.content(), "Selling from", "branch-banner-again");

  await page.fill("#pos-product-search", "MFS110");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-results button");
  await page.locator("#pos-serial-results button", { hasText: "QA-BR-001" }).click();
  notes.push("serial-select:sale");

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

  await page.click("#pos-complete");
  await page.waitForSelector(".alert-danger, #customer_phone:invalid", { timeout: 5000 }).catch(() => {});
  const emptyCustomer = await page.content();
  if (emptyCustomer.includes("alert-danger") || (await page.locator("#customer_phone:invalid").count()) > 0) {
    notes.push("error:customer-required");
  } else {
    issues.push("Complete sale with empty customer did not surface a required-field or validation error.");
  }

  await page.fill("#customer_phone", "9111199001");
  await page.waitForTimeout(400);
  await page.waitForFunction(() => (document.getElementById("pos-customer-status")?.textContent || "").includes("New customer"));
  notes.push("customer:new");
  await page.fill("#customer_name", "Browser QA Customer");
  await page.selectOption("#payment_method", "Cash");

  completePosts = 0;
  const submittingLock = await page.evaluate(() => {
    const form = document.getElementById("pos-counter-form");
    const button = document.getElementById("pos-complete");
    let clicks = 0;
    button.addEventListener("click", () => { clicks += 1; }, true);
    button.click();
    button.click();
    return {
      clicks,
      disabled: button.disabled,
      datasetBusy: button.getAttribute("aria-busy") || button.dataset.submitting || "",
      listenerCount: form?.getAttribute("data-submitting") || "",
    };
  });
  notes.push(`duplicate-click:${JSON.stringify(submittingLock)}`);
  if (!submittingLock.disabled && submittingLock.clicks >= 2 && !submittingLock.listenerCount) {
    issues.push("POS Complete sale does not disable or guard against a second click before navigation.");
  }
  await page.waitForURL(/pos\/sales\/\d+/, { timeout: 20000 });
  mustInclude(await page.content(), "INV-QAA-", "invoice-number");
  if (!(await page.content()).includes("3034.40") && !(await page.content()).includes("3,034.40")) {
    throw new Error("Missing expected copy for completed-total: 3034.40");
  }
  notes.push("saw:completed-total");
  notes.push(`sale:ok:posts=${completePosts}`);
  if (completePosts !== 1) {
    issues.push(`POS complete posted ${completePosts} times; expected one request after the submit lock.`);
  }

  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  await page.fill("#customer_phone", "9111199001");
  await page.waitForFunction(() => (document.getElementById("pos-customer-status")?.textContent || "").includes("Existing POS customer"), { timeout: 8000 });
  notes.push("customer:existing-lookup");
  const loadedName = await page.locator("#customer_name").inputValue();
  if (loadedName !== "Browser QA Customer") {
    issues.push(`Existing customer lookup filled name "${loadedName}" instead of Browser QA Customer.`);
  }

  await page.goto(`${baseUrl}/pos/sales`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "INV-QAA-", "sale-history");
  await page.locator("a", { hasText: "POS-" }).first().click();
  await page.waitForURL(/pos\/sales\/\d+/);

  await page.locator("a", { hasText: "Invoice" }).click();
  await page.waitForURL(/invoice/);
  mustInclude(await page.content(), "not a GST e-invoice", "invoice-disclaimer");
  mustInclude(await page.content(), "Print", "invoice-print");
  notes.push("invoice:ok");

  await page.goto(`${baseUrl}/pos/sales`, { waitUntil: "networkidle" });
  await page.locator("a", { hasText: "POS-" }).first().click();
  await page.waitForURL(/pos\/sales\/\d+/);
  await page.fill('form[action*="cancel"] input[name="reason"]', "Browser QA cancel restock");
  await page.locator('form[action*="cancel"] button.btn-outline-danger', { hasText: "Cancel sale" }).click();
  await page.waitForURL(/pos\/sales\/\d+/);
  mustInclude(await page.content(), "Cancelled", "cancelled");
  mustInclude(await page.content(), "Reversed", "cancel-finance-reversed");
  notes.push("cancel:ok");

  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  await page.fill("#pos-product-search", "MFS110");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-results button");
  await page.locator("#pos-serial-results button", { hasText: "QA-BR-HOLD" }).click();
  await page.fill("#customer_phone", "9111199003");
  await page.fill("#customer_name", "Browser QA Return Customer");
  await page.selectOption("#payment_method", "Cash");
  await page.click("#pos-complete");
  await page.waitForURL(/pos\/sales\/\d+/, { timeout: 20000 });
  await page.fill('form[action*="return"] input[name="reason"]', "Browser QA return restock");
  await page.locator('form[action*="return"] button.btn-outline-secondary', { hasText: "Return sale" }).click();
  await page.waitForFunction(() => document.body.innerText.includes("Returned"), { timeout: 20000 });
  mustInclude(await page.content(), "Returned", "returned");
  mustInclude(await page.content(), "Reversed", "return-finance-reversed");
  notes.push("return:ok");

  await page.goto(`${baseUrl}/inventory/serials`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "QA-BR-001", "serial-after-cancel");

  if (!hardwareEmail || !unassignedEmail || !agentEmail) {
    throw new Error("Hardware, unassigned, and agent emails are required for permission QA.");
  }

  await logout(page);
  await login(page, hardwareEmail);
  await page.goto(`${baseUrl}/dashboard`, { waitUntil: "networkidle" });
  await expandSidebar(page);
  if (await page.locator('a[data-nav-key="inventory.stock"]').count() === 0) {
    throw new Error("Hardware sidebar is missing Inventory stock.");
  }
  if (await page.locator('a[data-nav-key="pos.counter"]').count() === 0) {
    throw new Error("Hardware sidebar is missing POS counter.");
  }
  if (await page.locator('a[data-nav-key="inventory.products"]').count() > 0) {
    issues.push("Hardware sidebar still exposes Products.");
  } else {
    notes.push("hardware:no-products-nav");
  }
  if (await page.locator('a[data-nav-key="inventory.branches"]').count() > 0) {
    issues.push("Hardware sidebar still exposes Branches.");
  } else {
    notes.push("hardware:no-branches-nav");
  }
  const productsStatus = await statusOf(page, "/inventory/products");
  if (productsStatus !== 403) {
    issues.push(`Hardware GET /inventory/products returned ${productsStatus}, expected 403.`);
  } else {
    notes.push("hardware:products-403");
  }
  const branchesStatus = await statusOf(page, "/inventory/branches");
  if (branchesStatus !== 403) {
    issues.push(`Hardware GET /inventory/branches returned ${branchesStatus}, expected 403.`);
  } else {
    notes.push("hardware:branches-403");
  }
  const adjustStatus = await statusOf(page, "/inventory/adjustments/create");
  if (adjustStatus !== 403) {
    issues.push(`Hardware GET /inventory/adjustments/create returned ${adjustStatus}, expected 403.`);
  } else {
    notes.push("hardware:adjust-403");
  }

  await page.goto(`${baseUrl}/inventory/stock`, { waitUntil: "networkidle" });
  const hardwareStock = await page.content();
  mustInclude(hardwareStock, "QA Counter A", "hardware-qaa");
  mustNotInclude(hardwareStock, "QA Warehouse B", "hardware-no-qab");

  await page.goto(`${baseUrl}/inventory/transfers/create`, { waitUntil: "networkidle" });
  const transferOptions = await page.locator('select[name="to_branch_id"] option').allTextContents();
  if (transferOptions.some((text) => text.includes("QAB"))) {
    issues.push("Hardware assigned only to QAA can still select QAB as transfer destination.");
  } else {
    notes.push("hardware:transfer-no-qab");
  }

  await page.goto(`${baseUrl}/pos/counter`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "QAA", "hardware-pos-branch");
  const qabOption = await page.locator("#operating_branch_id option", { hasText: "QAB" }).count();
  if (qabOption > 0) {
    issues.push("Hardware POS branch selector includes unassigned QAB.");
  } else {
    notes.push("hardware:pos-no-qab");
  }

  await page.fill("#pos-product-search", "MFS110");
  await page.waitForSelector("#pos-product-results button");
  await page.click("#pos-product-results button");
  await page.waitForSelector("#pos-serial-results button");
  await page.locator("#pos-serial-results button", { hasText: "QA-HW-001" }).click();
  await page.fill("#customer_phone", "9111199002");
  await page.fill("#customer_name", "Hardware QA Customer");
  await page.selectOption("#payment_method", "UPI");
  await page.fill("#payment_reference", "UPI-QA-001");
  await page.click("#pos-complete");
  await page.waitForURL(/pos\/sales\/\d+/, { timeout: 20000 });
  mustInclude(await page.content(), "INV-QAA-", "hardware-invoice");
  mustNotInclude(await page.content(), "Cancel sale", "hardware-no-cancel");
  notes.push("hardware:sale-ok");

  const cancelAction = await page.locator('form[action*="cancel"]').count();
  if (cancelAction > 0) {
    issues.push("Hardware sale page still exposes a cancel form.");
  }

  await logout(page);
  await login(page, unassignedEmail);
  await page.goto(`${baseUrl}/inventory/stock`, { waitUntil: "networkidle" });
  mustInclude(await page.content(), "You are not assigned to a branch", "unassigned-warning");
  notes.push("unassigned:warning");

  await logout(page);
  await login(page, agentEmail);
  await page.goto(`${baseUrl}/dashboard`, { waitUntil: "networkidle" });
  await expandSidebar(page);
  if (await page.locator('a[data-nav-key="inventory.stock"]').count() > 0 || await page.locator('a[data-nav-key="pos.counter"]').count() > 0) {
    issues.push("Agent sidebar still links to Inventory or POS.");
  } else {
    notes.push("agent:no-inventory-nav");
  }
  const agentStock = await statusOf(page, "/inventory/stock");
  if (agentStock !== 403) {
    issues.push(`Agent GET /inventory/stock returned ${agentStock}, expected 403.`);
  } else {
    notes.push("agent:stock-403");
  }
  const agentPos = await statusOf(page, "/pos/counter");
  if (agentPos !== 403) {
    issues.push(`Agent GET /pos/counter returned ${agentPos}, expected 403.`);
  } else {
    notes.push("agent:pos-403");
  }

  const report = {
    ok: consoleErrors.length === 0 && pageErrors.length === 0 && failedRequests.length === 0 && issues.length === 0,
    notes,
    issues,
    completePosts,
    consoleErrors,
    pageErrors,
    failedRequests,
  };
  writeFileSync(reportPath, JSON.stringify(report, null, 2));
  if (!report.ok) {
    console.error(JSON.stringify(report, null, 2));
    process.exit(1);
  }
  console.log(JSON.stringify({ ok: true, notes, completePosts }, null, 2));
} catch (error) {
  await page.screenshot({ path: "/tmp/inventory-pos-browser-qa-fail.png", fullPage: true }).catch(() => {});
  writeFileSync(reportPath, JSON.stringify({
    ok: false,
    error: String(error),
    url: page.url(),
    notes,
    issues,
    completePosts,
    consoleErrors,
    pageErrors,
    failedRequests,
  }, null, 2));
  throw error;
} finally {
  await browser.close();
}
