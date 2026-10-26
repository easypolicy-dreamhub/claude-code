// Walks every tab of every sample at tablet and phone widths and fails if
//  (a) any control sticks out past the card that contains it, or
//  (b) a segmented button group (Male/Female, Done/In progress/...) is stretched wider than its buttons.
const { chromium } = require("playwright");
const path = require("node:path");
(async () => {
  const browser = await chromium.launch();
  const problems = new Set();
  for (const width of [1000, 768, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.route(/fonts\./, (r) => r.abort());
    await page.goto("file://" + path.resolve(__dirname, "../dist/index.html"));
    await page.getByRole("button", { name: "Load sample profiles" }).click();
    const count = await page.locator("select[aria-label=Profile] option").count();
    for (let i = 0; i < count; i++) {
      await page.locator("select[aria-label=Profile]").selectOption({ index: i });
      for (const tab of ["Profile", "Checklist", "Assets", "Outcome", "Report"]) {
        await page.getByRole("tab", { name: tab }).click();
        await page.evaluate(() => document.querySelectorAll("details").forEach((d) => (d.open = true)));
        const found = await page.evaluate(() => {
          const out = [];
          for (const el of document.querySelectorAll(".card input, .card select, .card textarea, .card button, .card .seg")) {
            const card = el.closest(".card");
            if (el.closest(".table-scroll")) continue;
            const r = el.getBoundingClientRect(), c = card.getBoundingClientRect();
            if (r.width && r.right > c.right + 1) out.push(`sticks out of its card by ${Math.round(r.right - c.right)}px: <${el.tagName.toLowerCase()}> "${(el.value || el.textContent || el.getAttribute("aria-label") || "").slice(0, 30)}"`);
          }
          for (const seg of document.querySelectorAll(".seg")) {
            const btns = [...seg.children].reduce((w, b) => w + b.getBoundingClientRect().width, 0);
            if (seg.getBoundingClientRect().width > btns + 20) out.push(`button group stretched: ${Math.round(seg.getBoundingClientRect().width)}px for ${Math.round(btns)}px of buttons ("${seg.textContent.slice(0, 30)}")`);
          }
          if (document.documentElement.scrollWidth > window.innerWidth + 1) out.push("page scrolls sideways");
          return out;
        });
        found.forEach((f) => problems.add(`${width}px | ${tab} | ${f}`));
      }
    }
    await page.close();
  }
  await browser.close();
  console.log(problems.size ? "LAYOUT PROBLEMS:\n" + [...problems].join("\n") : "no layout problems at 1000, 768 and 390 px");
  if (problems.size) process.exitCode = 1;
})();
