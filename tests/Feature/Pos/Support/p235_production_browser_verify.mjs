import { chromium } from "playwright-core";
import { execSync } from "node:child_process";
import { writeFileSync } from "node:fs";

const baseUrl = process.env.P235_PROD_BASE_URL || "https://desk.radiumbox.com";
const chromePath =
  process.env.P235_PROD_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";
const reportPath = process.env.P235_PROD_REPORT || "/tmp/p235-production-browser-verify.json";

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
record("hardware_nav", /hardware/i.test(dashHtml));
record("hardware_workspace_route", (await assertOk("/dashboard/hardware-workspace")) === 200);
record("hardware_workspace_query", (await assertOk("/dashboard?workspace=hardware")) === 200);
record("finance_invoices", (await assertOk("/finance/invoices")) === 200);

const branchId = execSync(
  "ssh -o BatchMode=yes ravi@187.127.129.16 \"/usr/local/lsws/lsphp84/bin/php /var/www/radium-desk/artisan tinker --execute=\\\"echo App\\\\\\\\Models\\\\\\\\InventoryBranch::where('code','DELHI-RETAIL')->value('id');\\\"\"",
  { encoding: "utf8" },
).trim();

await page.goto(`${baseUrl}/pos/counter?branch_id=${branchId}`, { waitUntil: "networkidle", timeout: 60000 });
record("pos_counter", !page.url().includes("/login") && (await page.locator("#customer_phone").count()) > 0);

const searchResponse = await page.request.get(`${baseUrl}/pos/customers/search?q=Gate4`);
const searchJson = searchResponse.ok() ? await searchResponse.json() : { customers: [] };
record(
  "customer_partial_name_search",
  searchResponse.ok() && Array.isArray(searchJson.customers) && searchJson.customers.length >= 1,
  `status=${searchResponse.status()} count=${searchJson.customers?.length ?? 0}`,
);

const phoneResponse = await page.request.get(`${baseUrl}/pos/customers/search?q=9000`);
const phoneJson = phoneResponse.ok() ? await phoneResponse.json() : { customers: [] };
record(
  "customer_partial_phone_search",
  phoneResponse.ok() && Array.isArray(phoneJson.customers) && phoneJson.customers.length >= 1,
  `status=${phoneResponse.status()} count=${phoneJson.customers?.length ?? 0}`,
);

const b2cShow = await page.request.get(`${baseUrl}/pos/customers/4`);
const b2cJson = b2cShow.ok() ? await b2cShow.json() : {};
record(
  "b2c_show_no_fabricated_address",
  b2cShow.ok() && b2cJson.found === true && (b2cJson.billing_address == null || b2cJson.billing_address === ""),
  `address=${b2cJson.billing_address ?? "(null)"} source=${b2cJson.billing_source ?? ""}`,
);

const customerCountBefore = execSync(
  "ssh -o BatchMode=yes ravi@187.127.129.16 \"/usr/local/lsws/lsphp84/bin/php /var/www/radium-desk/artisan tinker --execute=\\\"echo App\\\\\\\\Models\\\\\\\\InventoryCustomer::count();\\\"\"",
  { encoding: "utf8" },
).trim();

await page.fill("#customer_phone", "");
await page.locator("#customer_phone").pressSequentially("9820", { delay: 40 });
await page.waitForTimeout(800);
const b2bButton = page.locator("#pos-customer-results .list-group-item").filter({ hasText: "Phil Technologies" }).first();
record("b2b_results_visible", (await b2bButton.count()) > 0);
if (await b2bButton.count()) {
  await b2bButton.click();
  await page.waitForTimeout(500);
  record("b2b_name_populated", (await page.inputValue("#customer_name")).includes("Phil"));
  record("b2b_gstin_populated", (await page.inputValue("#buyer_gstin")).includes("27AAICP"));
  record("b2b_address_populated", (await page.inputValue("#billing_address")).includes("Harmony"));
  record("b2b_city_populated", (await page.inputValue("#billing_city")) === "Mumbai");
  record("b2b_state_populated", (await page.inputValue("#billing_state")) === "Maharashtra");
  record("b2b_pin_populated", (await page.inputValue("#billing_pincode")) === "400104");
  record("b2b_place_of_supply_populated", (await page.inputValue("#place_of_supply_state")) === "Maharashtra");
}

await page.fill("#customer_name", "");
await page.locator("#customer_name").pressSequentially("Gate4 B2C", { delay: 40 });
await page.waitForTimeout(800);
const b2cButton = page.locator("#pos-customer-results .list-group-item").filter({ hasText: "Gate4 B2C Walk-in" }).first();
record("b2c_results_visible", (await b2cButton.count()) > 0);
if (await b2cButton.count()) {
  await b2cButton.click();
  await page.waitForTimeout(500);
  record("b2c_name_populated", (await page.inputValue("#customer_name")).includes("Gate4 B2C"));
  record("b2c_phone_populated", (await page.inputValue("#customer_phone")).includes("9000099901"));
  record("b2c_gstin_cleared_after_b2b", (await page.inputValue("#buyer_gstin")) === "");
  record("b2c_address_cleared_after_b2b", (await page.inputValue("#billing_address")) === "");
  record("b2c_city_cleared_after_b2b", (await page.inputValue("#billing_city")) === "");
  record("b2c_place_of_supply", (await page.inputValue("#place_of_supply_state")) === "Delhi");
}

const customerCountAfter = execSync(
  "ssh -o BatchMode=yes ravi@187.127.129.16 \"/usr/local/lsws/lsphp84/bin/php /var/www/radium-desk/artisan tinker --execute=\\\"echo App\\\\\\\\Models\\\\\\\\InventoryCustomer::count();\\\"\"",
  { encoding: "utf8" },
).trim();
record("duplicate_customer_not_created", customerCountBefore === customerCountAfter, `before=${customerCountBefore} after=${customerCountAfter}`);

await page.fill("#pos-product-search", "RBMBAS50L1");
await page.waitForTimeout(900);
const productButton = page.locator("#pos-product-results .list-group-item").filter({ hasText: "RBMBAS50L1" }).first();
const productFound = (await productButton.count()) > 0;
record("serialized_product_found", productFound);
if (productFound) {
  await productButton.click();
  await page.waitForTimeout(500);
  const serialButtons = page.locator("#pos-serial-results .list-group-item");
  const serialCount = await serialButtons.count();
  if (serialCount >= 2) {
    await serialButtons.nth(0).click();
    await page.waitForTimeout(200);
    await serialButtons.nth(1).click();
    await page.waitForTimeout(300);
    const cartRows = page.locator("#pos-cart-body tr.pos-cart-row");
    const rowCount = await cartRows.count();
    const qtyValue = rowCount > 0 ? await cartRows.first().locator(".pos-qty").inputValue() : "0";
    const serialRemoveCount = await page.locator(".pos-serial-remove").count();
    record("two_serials_one_row", rowCount === 1 && qtyValue === "2" && serialRemoveCount === 2, `rows=${rowCount} qty=${qtyValue} serials=${serialRemoveCount}`);
    if (serialRemoveCount >= 1) {
      await page.locator(".pos-serial-remove").first().click();
      await page.waitForTimeout(200);
      const qtyAfter = await cartRows.first().locator(".pos-qty").inputValue();
      const serialAfter = await page.locator(".pos-serial-remove").count();
      record("remove_one_serial_decrements_qty", qtyAfter === "1" && serialAfter === 1, `qty=${qtyAfter} serials=${serialAfter}`);
      await page.locator(".pos-serial-remove").first().click();
      await page.waitForTimeout(200);
      record("remove_last_serial_clears_row", (await cartRows.count()) === 0);
    }
  } else {
    record("two_serials_one_row", false, `only ${serialCount} serials available`);
    record("remove_one_serial_decrements_qty", false, "skipped");
    record("remove_last_serial_clears_row", false, "skipped");
  }
} else {
  record("two_serials_one_row", false, "product not found");
  record("remove_one_serial_decrements_qty", false, "skipped");
  record("remove_last_serial_clears_row", false, "skipped");
}

await browser.close();

const summary = {
  baseUrl,
  allPassed: Object.values(checks).every((c) => c.pass),
  checks,
};
writeFileSync(reportPath, JSON.stringify(summary, null, 2));
console.log(JSON.stringify(summary, null, 2));
process.exit(summary.allPassed ? 0 : 1);
