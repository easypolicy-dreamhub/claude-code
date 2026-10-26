import test from "node:test";
import assert from "node:assert/strict";
import { newPlan } from "../src/model.js";
import { analyse } from "../src/engine/outcome.js";
import { samplePlans } from "../src/samples.js";

let n = 0;
const id = () => `id${++n}`;
const person = (name, relationship, extra = {}) => ({ id: id(), name, relationship, alive: true, minor: false, ...extra });
const asset = (name, category, value, route, allocations = [], extra = {}) => ({ id: id(), name, category, value, route, allocations, ...extra });
const total = (outcome, p) => outcome.current.people.find((x) => x.person.id === p.id)?.total ?? 0;
const planWith = (people, assets, extra = {}) => {
  const plan = newPlan("Test", false);
  plan.people = people;
  plan.assets = assets;
  return Object.assign(plan, extra);
};
const flag = (outcome, re) => outcome.flags.find((f) => re.test(f.text));

test("Distribution Act: spouse + two children → 1/3, 1/3, 1/3", () => {
  const spouse = person("S", "spouse");
  const a = person("A", "son");
  const b = person("B", "daughter");
  const o = analyse(planWith([spouse, a, b], [asset("Cash", "cash", 300000, "estate")]));
  assert.equal(Math.round(total(o, spouse)), 100000);
  assert.equal(Math.round(total(o, a)), 100000);
  assert.equal(Math.round(total(o, b)), 100000);
});

test("Distribution Act: spouse + children + parent → 1/4, 1/2, 1/4 (Mr A case)", () => {
  const spouse = person("Wife", "spouse");
  const k1 = person("K1", "son");
  const k2 = person("K2", "daughter");
  const mum = person("Mum", "mother");
  const o = analyse(planWith([spouse, k1, k2, mum], [asset("Cash", "cash", 400000, "estate")]));
  assert.equal(Math.round(total(o, spouse)), 100000);
  assert.equal(Math.round(total(o, k1)), 100000);
  assert.equal(Math.round(total(o, mum)), 100000);
});

test("Distribution Act: grandchildren take a dead child's share; childless dead child drops out (Mr T case)", () => {
  const c1 = person("C1", "son");
  const dead1 = person("D1", "son", { alive: false });
  const dead2 = person("D2", "daughter", { alive: false });
  const dead3 = person("D3", "son", { alive: false });
  const g1 = person("G1", "grandchild", { parentId: dead1.id });
  const g2 = person("G2", "grandchild", { parentId: dead1.id });
  const g3 = person("G3", "grandchild", { parentId: dead2.id });
  const o = analyse(planWith([c1, dead1, dead2, dead3, g1, g2, g3], [asset("Cash", "cash", 300000, "estate")]));
  assert.equal(Math.round(total(o, c1)), 100000);
  assert.equal(Math.round(total(o, g1)), 50000);
  assert.equal(Math.round(total(o, g3)), 100000);
});

test("#1 insurance: a brother nominated on a Malaysian policy holds it for the estate", () => {
  const spouse = person("Wife", "spouse");
  const brother = person("Bro", "brother");
  const o = analyse(planWith([spouse, brother], [asset("Life", "insurance", 200000, "nominee", [{ personId: brother.id, pct: 100 }])]));
  assert.equal(total(o, brother), 0);
  assert.equal(Math.round(total(o, spouse)), 200000); // falls into the estate; spouse takes it all under the Act
  assert.equal(o.current.outsideEstate, 0);
  assert.ok(flag(o, /receive the money as executor/));
});

test("#1 insurance: assignment lets the non-family nominee keep it", () => {
  const spouse = person("Wife", "spouse");
  const friend = person("Friend", "other");
  const o = analyse(planWith([spouse, friend], [asset("Life", "insurance", 200000, "nominee", [{ personId: friend.id, pct: 100 }], { assigned: true })]));
  assert.equal(total(o, friend), 200000);
  assert.equal(o.current.outsideEstate, 200000);
});

test("#1 insurance: spouse and child nominees are trust beneficiaries outside the estate and debts", () => {
  const spouse = person("Wife", "spouse");
  const kid = person("Kid", "son");
  const plan = planWith([spouse, kid], [asset("Life", "insurance", 300000, "nominee", [{ personId: spouse.id, pct: 50 }, { personId: kid.id, pct: 50 }])]);
  plan.liabilities = 100000;
  const o = analyse(plan);
  assert.equal(total(o, spouse), 150000);
  assert.equal(total(o, kid), 150000);
  assert.equal(o.current.netEstate, 0);
});

test("#3 insurance: a nominee who dies first — survivors take it all (para 6(2)); all dead → estate (6(3))", () => {
  const spouse = person("Wife", "spouse");
  const kid = person("Kid", "son", { alive: false });
  const policy = asset("Life", "insurance", 200000, "nominee", [{ personId: spouse.id, pct: 50 }, { personId: kid.id, pct: 50 }]);
  const o = analyse(planWith([spouse, kid], [policy]));
  assert.equal(total(o, spouse), 200000);
  assert.equal(o.current.outsideEstate, 200000);

  const dead = person("Dead", "spouse", { alive: false });
  const o2 = analyse(planWith([dead, kid], [asset("Life", "insurance", 200000, "nominee", [{ personId: dead.id, pct: 100 }])]));
  assert.equal(o2.current.outsideEstate, 0);
  assert.equal(o2.current.estatePool, 200000);
});

test("EPF: a nominee who dies first — their share goes to the estate", () => {
  const spouse = person("Wife", "spouse");
  const kid = person("Kid", "son", { alive: false });
  const epf = asset("EPF", "retirement", 100000, "nominee", [{ personId: spouse.id, pct: 50 }, { personId: kid.id, pct: 50 }]);
  const o = analyse(planWith([spouse, kid], [epf]));
  assert.equal(o.current.outsideEstate, 50000);
  assert.equal(o.current.estatePool, 50000);
});

test("#4 insurance: a parent is only a trust nominee when there is no living spouse or child", () => {
  const mum = person("Mum", "mother");
  const alone = analyse(planWith([mum], [asset("Life", "insurance", 100000, "nominee", [{ personId: mum.id, pct: 100 }])]));
  assert.equal(alone.current.outsideEstate, 100000);

  const spouse = person("Wife", "spouse");
  const withSpouse = analyse(planWith([mum, spouse], [asset("Life", "insurance", 100000, "nominee", [{ personId: mum.id, pct: 100 }])]));
  assert.equal(withSpouse.current.outsideEstate, 0);
  assert.ok(flag(withSpouse, /only holds a trust nomination when there is no spouse or child/));
});

test("#2 joint: three holders → the deceased's 1/3 share is estate, not the whole account (Mr Lim case)", () => {
  const sis = person("Sis", "sister");
  const account = asset("Joint account", "cash", 119151, "joint", [], { jointHolders: 3 });
  const o = analyse(planWith([sis], [account]));
  assert.equal(Math.round(o.current.totalAssets), 39717);
  assert.equal(Math.round(o.current.estatePool), 39717);
  assert.equal(o.current.outsideEstate, 0);
  assert.ok(flag(o, /jointly held/));
});

test("#2 joint: express survivorship passes the deceased's share outside the estate", () => {
  const sis = person("Sis", "sister");
  const account = asset("Joint account", "cash", 100000, "joint", [{ personId: sis.id, pct: 100 }], { jointHolders: 2, survivorship: true });
  const o = analyse(planWith([sis], [account]));
  assert.equal(total(o, sis), 50000);
  assert.equal(o.current.outsideEstate, 50000);
});

test("#5 Sabah: non-Muslim profile gets a high flag; Peninsular does not", () => {
  const kid = person("Kid", "son");
  const sabah = analyse(planWith([kid], [asset("Cash", "cash", 1000, "estate")], { state: "sabah" }));
  assert.equal(sabah.flags.find((f) => /not Sabah/.test(f.text))?.level, "high");
  const peninsular = analyse(planWith([kid], [asset("Cash", "cash", 1000, "estate")]));
  assert.ok(!flag(peninsular, /not Sabah/));
});

test("#6 will: a gift to a witness is void and falls into intestacy; validity answers raise flags", () => {
  const spouse = person("Wife", "spouse");
  const kid = person("Kid", "son");
  const plan = planWith([spouse, kid], [asset("Cash", "cash", 100000, "estate")]);
  plan.checklist.will = { status: "done", notes: "" };
  plan.will = [{ personId: kid.id, pct: 100 }];
  plan.voidWillIds = [kid.id];
  plan.willChecks = { twoWitnesses: "no", ageOk: "no" };
  const o = analyse(plan);
  assert.ok(Math.round(total(o, spouse)) > 0, "estate falls to the Distribution Act");
  assert.ok(flag(o, /witnessed the will and is also a beneficiary/));
  assert.ok(flag(o, /two witnesses present together/));
  assert.ok(flag(o, /under 18/));
});

test("#7 liquidity: flags when estate cash can't cover debts and transfer fees (Dato C case)", () => {
  const sibling = person("Sib", "brother");
  const houses = Array.from({ length: 14 }, (_, i) => asset(`House ${i + 1}`, "property", 1_000_000, "estate"));
  const o = analyse(planWith([sibling], houses));
  const f = flag(o, /may not have enough cash/);
  assert.equal(f?.level, "high");
  assert.match(f.text, /RM 140,000/);
});

test("#7 liquidity: no flag when there is enough cash", () => {
  const sibling = person("Sib", "brother");
  const o = analyse(planWith([sibling], [asset("House", "property", 500000, "estate"), asset("Cash", "cash", 200000, "estate")]));
  assert.ok(!flag(o, /may not have enough cash/));
});

test("#8 route: will → probate; no will → summary / small estate / letter of administration", () => {
  const kid = person("Kid", "son");
  const willPlan = planWith([kid], [asset("Cash", "cash", 100000, "estate")]);
  willPlan.checklist.will = { status: "done", notes: "" };
  assert.match(analyse(willPlan).route.title, /Grant of Probate/);

  assert.match(analyse(planWith([kid], [asset("Cash", "cash", 500000, "estate")])).route.title, /Summary Distribution/);
  assert.match(analyse(planWith([kid], [asset("Cash", "cash", 700000, "estate")])).route.title, /Distribution Order/);
  assert.match(analyse(planWith([kid], [asset("House", "property", 500000, "estate")])).route.title, /Distribution Order/);
  assert.match(analyse(planWith([kid], [asset("House", "property", 6_000_000, "estate")])).route.title, /Letter of Administration/);
});

test("#8 route: Muslim profiles have no route (not covered)", () => {
  const plan = planWith([person("Kid", "son")], [asset("Cash", "cash", 1000, "estate")]);
  plan.muslim = true;
  assert.equal(analyse(plan).route, null);
});

test("#11 business type adds a note", () => {
  const kid = person("Kid", "son");
  const o = analyse(planWith([kid], [asset("Shop", "business", 100000, "estate", [], { businessType: "partnership" })]));
  assert.ok(flag(o, /dissolved on a partner's death/));
});

test("Muslim: faraid and the nominee-held-for-heirs rule are unchanged", () => {
  const plan = newPlan("M", true);
  const wife = person("W", "spouse");
  const son = person("S", "son");
  plan.people = [wife, son];
  plan.assets = [asset("Cash", "cash", 800000, "estate"), asset("Takaful", "insurance", 200000, "nominee", [{ personId: wife.id, pct: 100 }])];
  const o = analyse(plan);
  assert.equal(Math.round(total(o, wife)), 125000); // 1/8 of 1,000,000
  assert.equal(Math.round(total(o, son)), 875000);
});

test("sample profiles run without errors and keep their intended story", () => {
  const [ahmad, david, michelle] = samplePlans().map(analyse);
  assert.ok(flag(ahmad, /ignored: an heir cannot take under a wasiat/));
  assert.ok(flag(david, /still names an ex-spouse as a beneficiary/));
  assert.ok(flag(david, /the ex-spouse would keep the payout/));
  assert.ok(flag(michelle, /marriage automatically revokes an existing CPF nomination/));
});

// Estate Planning Scenarios Summary (trainer handout).
test("scenario 3/4: trust nominee beats the will; a sibling nominee holds for the estate and its creditors", () => {
  const wife = person("Bernice", "spouse");
  const brother = person("Daniel", "brother");
  const s3 = planWith([wife, brother], [asset("Policy", "insurance", 100000, "nominee", [{ personId: wife.id, pct: 100 }])]);
  s3.checklist.will = { status: "done", notes: "" };
  s3.will = [{ personId: brother.id, pct: 100 }];
  assert.equal(total(analyse(s3), wife), 100000);

  const s4 = planWith([wife, brother], [asset("Policy", "insurance", 100000, "nominee", [{ personId: brother.id, pct: 100 }])]);
  s4.checklist.will = { status: "done", notes: "" };
  s4.will = [{ personId: wife.id, pct: 100 }];
  s4.liabilities = 30000;
  const o4 = analyse(s4);
  assert.equal(total(o4, brother), 0);
  assert.equal(total(o4, wife), 70000);
});

test("scenario 5: trust status depends on marital status on the nomination date", () => {
  const wife = person("Bernice", "spouse");
  const mum = person("Mum", "mother");
  const policy = (name, nominee, date) => asset(name, "insurance", 100000, "nominee", [{ personId: nominee.id, pct: 100 }], { nominatedOn: date });
  const plan = planWith([wife, mum], [
    policy("A", mum, "2015-01-01"),
    policy("B", mum, "2020-01-01"),
    policy("C", wife, "2016-01-01"),
    policy("D", wife, "2021-01-01"),
  ], { marriedOn: "2018-06-01" });
  const o = analyse(plan);
  const from = (p, label) => o.current.people.find((x) => x.person.id === p.id).contributions.find((c) => c.label.startsWith(label + " ("))?.amount ?? 0;
  assert.equal(from(mum, "A"), 100000);
  assert.equal(from(mum, "B"), 0);
  assert.equal(from(wife, "C"), 0);
  assert.equal(from(wife, "D"), 100000);
  assert.equal(o.current.estatePool, 200000);
});

test("scenario 6/10: a minor's trust policy is held by the surviving parent; no high flag for that money", () => {
  const wife = person("Bernice", "spouse");
  const cindy = person("Cindy", "daughter", { minor: true });
  const o = analyse(planWith([wife, cindy], [asset("Policy 2", "insurance", 100000, "nominee", [{ personId: cindy.id, pct: 100 }])]));
  assert.match(flag(o, /statutory trustee/).text, /Bernice as statutory trustee/);
  assert.ok(!flag(o, /Minors cannot give a valid receipt/));
});

test("scenario 7b: no surviving parent → Amanah Raya holds the minor's trust policy", () => {
  const cindy = person("Cindy", "daughter", { minor: true });
  const o = analyse(planWith([cindy], [asset("Policy", "insurance", 100000, "nominee", [{ personId: cindy.id, pct: 100 }])]));
  assert.ok(flag(o, /Amanah Raya/));
});
