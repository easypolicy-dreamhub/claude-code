// Loads a heavy profile (14 houses, joint accounts, trusts, foreign assets...) and fails if any tab shows a money amount without thousand separators.
const { chromium } = require("playwright");
const mk = (muslim) => {
  const P = (id, name, relationship, extra = {}) => ({ id, name, relationship, alive: true, minor: false, ...extra });
  const people = [P("w", "Wife", "spouse"), P("s", "Son", "son"), P("d", "Daughter", "daughter", { minor: true }), P("b", "Brother", "brother"), P("x", "Ex", "ex-spouse"), P("m", "Mum", "mother")];
  const A = (id, name, category, value, route, allocations = [], extra = {}) => ({ id, name, category, value, route, allocations, ...extra });
  const assets = [
    ...Array.from({ length: 14 }, (_, i) => A("h" + i, "House " + (i + 1), "property", 1250000, "estate")),
    A("j", "Joint account", "cash", 1234567, "joint", [], { jointHolders: 3 }),
    A("j2", "Joint home", "property", 2345678, "joint", [{ personId: "w", pct: 100 }], { jointHolders: 2, survivorship: true }),
    A("i1", "Life A", "insurance", 1500000, "nominee", [{ personId: "b", pct: 100 }]),
    A("i2", "Life B", "insurance", 2500000, "nominee", [{ personId: "x", pct: 50 }, { personId: "d", pct: 50 }]),
    A("e", "EPF", "retirement", 987654, "nominee", [{ personId: "x", pct: 100 }]),
    A("c", "CPF", "retirement", 456789, "nominee", [{ personId: "w", pct: 100 }], { foreign: true }),
    A("sg", "SG policy", "insurance", 345678, "nominee", [{ personId: "m", pct: 100 }], { foreign: true, nominationType: "trust" }),
    A("t", "Trust", "cash", 1000000, "trust", [{ personId: "s", pct: 100 }]),
    A("hb", "Hibah", "property", 3000000, "hibah", [{ personId: "s", pct: 100 }]),
    A("f", "Foundation", "investments", 5000000, "foundation"),
    A("bz", "Holding Sdn Bhd", "business", 4000000, "estate", [], { businessType: "holdco" }),
    A("sp", "Foreign villa", "property", 6000000, "estate", [], { foreign: true }),
  ];
  const base = { id: "p" + muslim, clientName: "Stress " + (muslim ? "Muslim" : "Non-Muslim"), muslim, jurisdiction: "MY", state: muslim ? "peninsular" : "sabah", clientGender: "male", nomineeHeldForHeirs: true, people, assets, liabilities: 3456789, adminCostEstimate: 123456, marriedOn: "2018-01-01",
    checklist: { will: { status: "done", notes: "", date: "2015-05-05" }, retirement: { status: "done", notes: "", date: "2015-05-05" }, insurance: { status: "done", notes: "", date: "2015-05-05" } },
    will: [{ personId: "s", pct: 40 }, { personId: "x", pct: 30 }, { personId: "b", pct: 30 }], willChecks: { twoWitnesses: "no", ageOk: "no" }, voidWillIds: ["b"], advisorNotes: "" };
  return base;
};
(async () => {
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1000, height: 900 } });
  const errs = []; page.on("pageerror", (e) => errs.push(String(e)));
  await page.route(/fonts\./, (r) => r.abort());
  await page.goto("file://" + require("node:path").resolve(__dirname, "../dist/index.html"));
  await page.evaluate((d) => localStorage.setItem("legacy-planner.v1", JSON.stringify(d)), { plans: [mk(false), mk(true)], activeId: "pfalse" });
  await page.reload();
  const hits = new Set(); let moneyShown = 0;
  for (let i = 0; i < 2; i++) {
    await page.locator("select[aria-label=Profile]").selectOption({ index: i });
    for (const tab of ["Profile", "Checklist", "Assets", "Outcome", "Report"]) {
      await page.getByRole("tab", { name: tab }).click();
      await page.evaluate(() => document.querySelectorAll("details").forEach((d) => (d.open = true)));
      const r = await page.evaluate(() => {
        const vals = [...document.querySelectorAll("input,textarea")].filter((e) => !["date", "checkbox", "file"].includes(e.type)).map((e) => e.value);
        return { t: document.body.innerText + "\n" + vals.join("\n") };
      });
      moneyShown += (r.t.match(/RM\s?[\d,]{5,}/g) || []).length;
      for (const m of r.t.matchAll(/(?<![\d,.\-\/:])\d{4,}(?![\d,]*\d{3}\b)(?![\d\-\/:])/g)) {
        const n = Number(m[0]); if ((n >= 1900 && n <= 2100) ) continue;
        hits.add(`${tab}: …${r.t.slice(Math.max(0, m.index - 45), m.index + m[0].length + 15).replace(/\s+/g, " ")}…`);
      }
    }
  }
  console.log("formatted RM amounts seen:", moneyShown);
  console.log(hits.size ? "UNFORMATTED:\n" + [...hits].join("\n") : "no unformatted numbers found");
  console.log(errs.length ? "ERRORS " + errs : "no page errors"); if (hits.size || errs.length) process.exitCode = 1;
  await b.close();
})();
