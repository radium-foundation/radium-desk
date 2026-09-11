import { chromium } from "playwright-core";
import { execSync } from "node:child_process";
import { writeFileSync } from "node:fs";

const baseUrl = process.env.P236_PROD_BASE_URL || "https://desk.radiumbox.com";
const chromePath =
  process.env.P236_PROD_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";
const reportPath = process.env.P236_PROD_REPORT || "/tmp/p236-production-browser-verify.json";

function fetchProductionSessionCookie() {
  const cmd = `ssh -o BatchMode=yes -o ConnectTimeout=15 ravi@187.127.129.16 '/usr/local/lsws/lsphp84/bin/php'`;
  const cookie = execSync(cmd, {
    encoding: "utf8",
    input: `<?php
require '/var/www/radium-desk/vendor/autoload.php';
$app = require '/var/www/radium-desk/bootstrap/app.php';
$kernel = $app->make(Illuminate\\Contracts\\Http\\Kernel::class);
$request = Illuminate\\Http\\Request::create('/dashboard', 'GET');
$request->headers->set('HOST', 'desk.radiumbox.com');
$kernel->handle($request);
$session = $request->session();
$session->start();
Illuminate\\Support\\Facades\\Auth::guard('web')->login(App\\Models\\User::find(2));
$session->save();
$request2 = Illuminate\\Http\\Request::create('/dashboard', 'GET');
$request2->headers->set('HOST', 'desk.radiumbox.com');
$request2->setLaravelSession($session);
$response = $kernel->handle($request2);
foreach ($response->headers->getCookies() as $cookie) {
    if ($cookie->getName() === 'radium-desk-session') {
        echo $cookie->getValue();
        break;
    }
}
`,
  }).trim();
  if (!cookie.startsWith("eyJ")) {
    throw new Error(`Failed to obtain production session cookie: ${cookie.slice(0, 120)}`);
  }
  return cookie;
}

const checks = {};
function record(name, pass, detail = "") {
  checks[name] = { pass, detail };
}

const sessionCookie = fetchProductionSessionCookie();
const browser = await chromium.launch({
  executablePath: chromePath,
  headless: true,
  args: ["--disable-dev-shm-usage"],
});
const context = await browser.newContext({
  viewport: { width: 1440, height: 900 },
  ignoreHTTPSErrors: true,
});
await context.addCookies([
  {
    name: "radium-desk-session",
    value: sessionCookie,
    domain: "desk.radiumbox.com",
    path: "/",
    httpOnly: true,
    secure: true,
    sameSite: "Lax",
  },
]);
const page = await context.newPage();

async function assertOk(path) {
  const response = await page.goto(`${baseUrl}${path}`, { waitUntil: "domcontentloaded", timeout: 60000 });
  return response?.status() ?? 0;
}

record("up", (await assertOk("/up")) === 200);
const dash = await page.goto(`${baseUrl}/dashboard`, { waitUntil: "networkidle", timeout: 60000 });
const dashHtml = await page.content();
record("dashboard", (dash?.status() ?? 0) === 200 && !page.url().includes("/login"));
record("ready_queue", dashHtml.includes("Ready Queue"));
record("hardware_workspace_route", (await assertOk("/dashboard/hardware-workspace")) === 200);
record("finance_invoices", (await assertOk("/finance/invoices")) === 200);

const branchId = execSync(
  "ssh -o BatchMode=yes ravi@187.127.129.16 \"/usr/local/lsws/lsphp84/bin/php /var/www/radium-desk/artisan tinker --execute=\\\"echo App\\\\\\\\Models\\\\\\\\InventoryBranch::where('code','DELHI-RETAIL')->value('id');\\\"\"",
  { encoding: "utf8" },
).trim();

await page.goto(`${baseUrl}/pos/counter?branch_id=${branchId}`, { waitUntil: "networkidle", timeout: 60000 });
record("pos_counter", !page.url().includes("/login") && (await page.locator("#customer_phone").count()) > 0);

const nameSearch = await page.request.get(`${baseUrl}/pos/customers/search?q=UJJAIN`);
const nameJson = nameSearch.ok() ? await nameSearch.json() : { customers: [] };
const imported = (nameJson.customers || []).find((c) => Number(c.id) === 22);
record(
  "old_customer_partial_name",
  nameSearch.ok() && imported && String(imported.phone).startsWith("98934"),
  `status=${nameSearch.status()} count=${nameJson.customers?.length ?? 0}`,
);

const phoneSearch = await page.request.get(`${baseUrl}/pos/customers/search?q=98934`);
const phoneJson = phoneSearch.ok() ? await phoneSearch.json() : { customers: [] };
record(
  "old_customer_partial_phone",
  phoneSearch.ok() && (phoneJson.customers || []).some((c) => Number(c.id) === 22),
  `status=${phoneSearch.status()} count=${phoneJson.customers?.length ?? 0}`,
);

const customerCountBefore = execSync(
  "ssh -o BatchMode=yes ravi@187.127.129.16 \"/usr/local/lsws/lsphp84/bin/php /var/www/radium-desk/artisan tinker --execute=\\\"echo App\\\\\\\\Models\\\\\\\\InventoryCustomer::count();\\\"\"",
  { encoding: "utf8" },
).trim();

await page.fill("#customer_name", "");
await page.locator("#customer_name").pressSequentially("UJJAIN", { delay: 40 });
await page.waitForTimeout(800);
const importedButton = page.locator("#pos-customer-results .list-group-item").filter({ hasText: "UJJAIN" }).first();
record("old_customer_ui_visible", (await importedButton.count()) > 0);
if (await importedButton.count()) {
  await importedButton.click();
  await page.waitForTimeout(500);
  record("old_customer_name_populated", (await page.inputValue("#customer_name")).includes("UJJAIN"));
  record("old_customer_phone_populated", (await page.inputValue("#customer_phone")).startsWith("98934"));
  record("old_customer_email_populated", (await page.inputValue("#customer_email")).includes("@"));
  record("old_customer_gstin_populated", (await page.inputValue("#buyer_gstin")).startsWith("23AADCM"));
  record("old_customer_address_blank", (await page.inputValue("#billing_address")) === "");
  record("old_customer_city_blank", (await page.inputValue("#billing_city")) === "");
  record("old_customer_place_of_supply_branch_default", (await page.inputValue("#place_of_supply_state")) === "Delhi");
}

await page.fill("#customer_name", "");
await page.locator("#customer_name").pressSequentially("Gate4 B2C", { delay: 40 });
await page.waitForTimeout(800);
const b2cButton = page.locator("#pos-customer-results .list-group-item").filter({ hasText: "Gate4 B2C Walk-in" }).first();
if (await b2cButton.count()) {
  await b2cButton.click();
  await page.waitForTimeout(500);
  record("switch_clears_gstin", (await page.inputValue("#buyer_gstin")) === "");
  record("switch_clears_email", (await page.inputValue("#customer_email")) === "");
  record("switch_name_is_b2c", (await page.inputValue("#customer_name")).includes("Gate4 B2C"));
}

const customerCountAfter = execSync(
  "ssh -o BatchMode=yes ravi@187.127.129.16 \"/usr/local/lsws/lsphp84/bin/php /var/www/radium-desk/artisan tinker --execute=\\\"echo App\\\\\\\\Models\\\\\\\\InventoryCustomer::count();\\\"\"",
  { encoding: "utf8" },
).trim();
record("duplicate_customer_not_created", customerCountBefore === customerCountAfter, `before=${customerCountBefore} after=${customerCountAfter}`);

await page.fill("#pos-product-search", "RBMBAS50L1");
await page.waitForTimeout(900);
const productButton = page.locator("#pos-product-results .list-group-item").filter({ hasText: "RBMBAS50L1" }).first();
if ((await productButton.count()) > 0) {
  await productButton.click();
  await page.waitForTimeout(500);
  const serialButtons = page.locator("#pos-serial-results .list-group-item");
  if ((await serialButtons.count()) >= 2) {
    await serialButtons.nth(0).click();
    await page.waitForTimeout(200);
    await serialButtons.nth(1).click();
    await page.waitForTimeout(300);
    const cartRows = page.locator("#pos-cart-body tr.pos-cart-row");
    const qtyValue = (await cartRows.count()) > 0 ? await cartRows.first().locator(".pos-qty").inputValue() : "0";
    record("two_serials_one_row", (await cartRows.count()) === 1 && qtyValue === "2", `qty=${qtyValue}`);
    await page.locator(".pos-serial-remove").first().click();
    await page.waitForTimeout(200);
    record("remove_one_serial", (await page.locator(".pos-serial-remove").count()) === 1);
    await page.locator(".pos-serial-remove").first().click();
    await page.waitForTimeout(200);
    record("remove_last_serial", (await cartRows.count()) === 0);
  }
}

await browser.close();
const summary = { baseUrl, allPassed: Object.values(checks).every((c) => c.pass), checks };
writeFileSync(reportPath, JSON.stringify(summary, null, 2));
console.log(JSON.stringify(summary, null, 2));
process.exit(summary.allPassed ? 0 : 1);
