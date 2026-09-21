const { writeFileSync } = require("node:fs");
const path = require("node:path");

const playwrightRoot = path.resolve(__dirname, "../../../../../radium-desk/node_modules");
module.paths.push(playwrightRoot);
const { chromium } = require("playwright-core");

const baseUrl = process.env.INVENTORY_POS_QA_BASE_URL || "http://127.0.0.1:8899";
const hardwareEmail = process.env.INVENTORY_POS_QA_HARDWARE_EMAIL || "qa-inventory-pos-hardware@radium.local";
const password = process.env.INVENTORY_POS_QA_PASSWORD || "password";
const reportPath = process.env.POS_MULTI_SERIAL_E2E_REPORT || "/tmp/pos-multi-serial-e2e-uat.json";
const chromePath =
  process.env.INVENTORY_POS_QA_CHROME ||
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";

const serials = ["MERGE-SN-001", "MERGE-SN-002", "MERGE-SN-003"];
const productSku = "MFS110-BROWSER";

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

(async () => {
  const issues = [];
  const notes = [];
  const report = {
    ok: false,
    baseUrl,
    productSku,
    serials,
    preSubmit: {},
    submission: {},
    reload: {},
    notes,
    issues,
  };

  const browser = await chromium.launch({ headless: true, executablePath: chromePath });
  const page = await browser.newPage();

  try {
    await login(page);
    await ensureCounterBranch(page);

    await page.fill("#pos-product-search", productSku);
    await page.waitForSelector("#pos-product-results button");
    await page.click("#pos-product-results button");
    await page.waitForSelector("#pos-serial-entry");

    await page.fill("#pos-serial-entry", serials.join(" "));
    await page.press("#pos-serial-entry", "Enter");
    await page.waitForTimeout(600);

    const selectedCount = await page.locator("#pos-serial-selected .font-monospace").count();
    const qty = await page.locator(".pos-qty").inputValue();
    const hiddenSerials = await page.locator('#pos-cart-fields input[name="lines[0][serials]"]').inputValue();
    const hiddenSerialList = hiddenSerials.split("\n").filter(Boolean).sort();
    const chipSerials = (await page.locator("#pos-serial-selected .font-monospace").allTextContents()).sort();
    const cartSerialText = await page.locator("#pos-cart-body tr.pos-cart-row .small.text-muted").innerText();

    report.preSubmit = {
      selectedCount,
      qty,
      hiddenSerialList,
      chipSerials,
      cartSerialText,
    };

    if (selectedCount !== 3) issues.push(`Expected 3 selected serial chips, got ${selectedCount}`);
    if (qty !== "3") issues.push(`Expected qty 3 before submit, got ${qty}`);
    if (hiddenSerialList.join(",") !== serials.slice().sort().join(",")) {
      issues.push(`Hidden serial fields mismatch: ${hiddenSerialList.join(",")}`);
    }
    serials.forEach((serial) => {
      if (!chipSerials.includes(serial)) issues.push(`Missing chip for ${serial}`);
      if (!cartSerialText.includes(serial)) issues.push(`Missing cart serial text for ${serial}`);
    });

    await page.fill("#customer_phone", "9111199777");
    await page.fill("#customer_name", "Multi Serial E2E UAT");
    await page.selectOption("#payment_method", "Cash");

    const [response] = await Promise.all([
      page.waitForNavigation({ waitUntil: "networkidle", timeout: 30000 }),
      page.click("#pos-complete"),
    ]);

    const finalUrl = page.url();
    const saleMatch = finalUrl.match(/\/pos\/sales\/(\d+)/);
    report.submission = {
      success: saleMatch !== null && response.status() < 400,
      status: response?.status() ?? null,
      finalUrl,
      saleId: saleMatch ? saleMatch[1] : null,
      bodyHasError: (await page.content()).includes("alert-danger"),
    };

    if (!report.submission.success) {
      issues.push(`Sale submission failed: ${finalUrl} status=${report.submission.status}`);
    }
    if (report.submission.bodyHasError) {
      issues.push("Sale show page contains alert-danger after submit");
    }

    if (report.submission.saleId) {
      const showContent = await page.content();
      report.reload = {
        qtyVisible: showContent.includes(">3<") || showContent.includes("> 3 <"),
        serialsVisible: serials.every((serial) => showContent.includes(serial)),
        productVisible: showContent.includes(productSku) || showContent.includes("MFS110"),
      };

      serials.forEach((serial) => {
        if (!report.reload.serialsVisible) return;
      });
      if (!report.reload.serialsVisible) {
        issues.push("Not all serials visible on sale show page after submit");
      }

      await page.reload({ waitUntil: "networkidle" });
      const reloaded = await page.content();
      report.reload.afterReload = {
        serialsVisible: serials.every((serial) => reloaded.includes(serial)),
        qtyStillVisible: reloaded.includes(">3<") || reloaded.includes("> 3 <") || reloaded.includes("Qty</th>"),
      };
      if (!report.reload.afterReload.serialsVisible) {
        issues.push("Serials missing after reload");
      }

      await page.goto(`${baseUrl}/pos/sales`, { waitUntil: "networkidle" });
      const salesList = await page.content();
      report.reload.salesListContainsSale = salesList.includes(report.submission.saleId);
      if (!report.reload.salesListContainsSale) {
        issues.push("Created sale not found in POS sales list");
      }
    }
  } catch (error) {
    issues.push(error.message);
  } finally {
    await browser.close();
  }

  report.ok = issues.length === 0;
  writeFileSync(reportPath, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report));
  process.exit(report.ok ? 0 : 1);
})();
