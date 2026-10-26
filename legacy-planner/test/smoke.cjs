// Opens dist/index.html in headless Chromium, walks every tab for every sample, and fails on any page error.
const { chromium } = require("playwright");
const path = require("node:path");

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });
  const errors = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  page.on("console", (m) => m.type() === "error" && !/fonts\.g|net::ERR/.test(m.text()) && errors.push(m.text()));
  await page.route(/fonts\.(googleapis|gstatic)\.com/, (r) => r.abort());

  await page.goto("file://" + path.resolve(__dirname, "../dist/index.html"));
  await page.getByRole("button", { name: "Load sample profiles" }).click();

  const names = await page.locator("select[aria-label=Profile] option").allTextContents();
  for (let i = 0; i < names.length; i++) {
    await page.locator("select[aria-label=Profile]").selectOption({ index: i });
    for (const tab of ["Profile", "Checklist", "Assets", "Outcome", "Report"]) {
      await page.getByRole("tab", { name: tab }).click();
      await page.waitForTimeout(50);
    }
    console.log("ok:", names[i]);
  }

  // Exercise the new controls on the David Tan sample (non-Muslim).
  await page.locator("select[aria-label=Profile]").selectOption({ label: "Sample: David Tan (MY)" });
  await page.getByRole("tab", { name: "Assets" }).click();
  await page.getByRole("button", { name: "+ Add asset" }).click();
  await page.locator("select").filter({ hasText: "Through the estate" }).last().selectOption("joint");
  await page.getByText("Number of joint holders").waitFor();
  await page.getByRole("tab", { name: "Checklist" }).click();
  await page.getByText("Will validity (Wills Act 1959)").waitFor();
  await page.getByRole("tab", { name: "Outcome" }).click();
  await page.getByText("Likely route to settle the estate").waitFor();
  await page.screenshot({ path: process.argv[2] || "smoke-outcome.png", fullPage: true });

  // Reload: saved data survives, and an old-format plan loads.
  await page.evaluate(() => localStorage.setItem("legacy-planner.v1", JSON.stringify({ plans: [{ id: "old", clientName: "Old save", muslim: false, jurisdiction: "MY", people: [], assets: [{ id: "a", name: "Joint", category: "cash", value: 90000, route: "joint", allocations: [] }], checklist: {}, will: [], liabilities: 0 }], activeId: "old" })));
  await page.reload();
  await page.getByRole("tab", { name: "Outcome" }).click();
  await page.getByText("If Old save passed away today").waitFor();

  await browser.close();
  if (errors.length) { console.error("PAGE ERRORS:\n" + errors.join("\n")); process.exit(1); }
  console.log("smoke test passed, no page errors");
})().catch((e) => { console.error(e); process.exit(1); });
