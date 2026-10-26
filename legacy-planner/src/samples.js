import { uid } from "./format.js";
import { newPlan } from "./model.js";

const person = (name, relationship, extra = {}) => ({ id: uid(), name, relationship, alive: true, minor: false, ...extra });
const asset = (name, category, value, route, allocations = [], nominationType, foreign, extra = {}) => ({
  id: uid(),
  name,
  category,
  value,
  route,
  allocations,
  nominationType,
  foreign,
  ...extra,
});
const item = (status, notes = "", date) => ({ status, notes, date });

export function sampleMichelle() {
  const plan = newPlan("Sample: Michelle Ooi (MY)", false);
  plan.clientGender = "female";
  const kevin = person("Kevin Ooi", "spouse");
  const ethan = person("Ethan Ooi", "son");
  const sophie = person("Sophie Ooi", "daughter", { minor: true });
  plan.people = [kevin, ethan, sophie];
  plan.liabilities = 50000;
  plan.marriedOn = "2018-06-10";
  plan.checklist = {
    ...plan.checklist,
    will: item("done", "Drafted by a Malaysian firm for the worldwide estate.", "2019-04-02"),
    executor: item("done", "A sibling agreed to act."),
    insurance: item("done", "Malaysian takaful plus a Singapore term policy from 6 years working there.", "2016-09-01"),
    retirement: item("done", "EPF nominee is spouse. CPF (from working in Singapore) also nominated to spouse.", "2017-02-14"),
    joint: item("todo"),
    foreign_assets: item("in_progress", "CPF, a Singapore unit trust account and a Singapore policy identified so far."),
    business_trust: item("na"),
    business_agreement: item("na"),
    charity: item("na"),
    hibah: item("na"),
  };
  plan.will = [
    { personId: kevin.id, pct: 60 },
    { personId: ethan.id, pct: 20 },
    { personId: sophie.id, pct: 20 },
  ];
  plan.willChecks = { twoWitnesses: "yes", ageOk: "yes" };
  plan.assets = [
    asset("Family home (Malaysia)", "property", 750000, "estate"),
    asset("Savings (Malaysia)", "cash", 90000, "estate"),
    asset("Unit trust account (Singapore)", "investments", 80000, "estate", [], undefined, true),
    asset("EPF (Malaysia)", "retirement", 180000, "nominee", [{ personId: kevin.id, pct: 100 }]),
    asset("CPF (Singapore)", "retirement", 260000, "nominee", [{ personId: kevin.id, pct: 100 }], undefined, true),
    asset("Term life policy (Singapore)", "insurance", 200000, "nominee", [{ personId: ethan.id, pct: 50 }, { personId: sophie.id, pct: 50 }], "trust", true),
    asset("Family takaful (Malaysia)", "insurance", 150000, "nominee", [{ personId: kevin.id, pct: 100 }]),
  ];
  plan.advisorNotes =
    "Sample data for demonstration only. Worked in Singapore for 6 years before returning to Malaysia — CPF, a Singapore unit trust account and a Singapore insurance policy were never flagged to the Malaysian will-drafting lawyer, and the retirement and insurance nominations were made before the marriage.";
  return plan;
}

export function sampleAhmad() {
  const plan = newPlan("Sample: Ahmad bin Ismail (MY)", true);
  const aminah = person("Siti Aminah", "spouse");
  const hakim = person("Hakim", "son");
  const aisyah = person("Nur Aisyah", "daughter", { minor: true });
  const zainab = person("Puan Zainab", "mother");
  const wakaf = person("Yayasan Wakaf Kampung", "other");
  const farah = person("Farah (niece)", "other");
  plan.people = [aminah, hakim, aisyah, zainab, wakaf, farah];
  plan.liabilities = 60000;
  plan.checklist = {
    ...plan.checklist,
    will: item("done", "Wasiat drawn up with a Syariah-compliant firm.", "2025-01-15"),
    insurance: item("done", "Family takaful, nominee is wife."),
    retirement: item("done", "EPF nominee is wife."),
    poa: item("todo"),
    hibah: item("todo", "Considering a hibah of the family home to wife."),
    business_trust: item("na"),
    business_agreement: item("na"),
  };
  plan.will = [
    { personId: wakaf.id, pct: 10 },
    { personId: farah.id, pct: 20 },
    { personId: hakim.id, pct: 5 },
  ];
  plan.assets = [
    asset("Family home", "property", 800000, "estate"),
    asset("Savings", "cash", 120000, "estate"),
    asset("ASB units", "investments", 150000, "estate"),
    asset("EPF (Account 1 and 2)", "retirement", 350000, "nominee", [{ personId: aminah.id, pct: 100 }]),
    asset("Family takaful", "insurance", 300000, "nominee", [{ personId: aminah.id, pct: 100 }]),
    asset("Sole proprietorship", "business", 400000, "estate", [], undefined, undefined, { businessType: "sole" }),
  ];
  plan.advisorNotes = "Sample data for demonstration only. Note the wasiat includes a gift to a son, who is an heir.";
  return plan;
}

export function sampleDavid() {
  const plan = newPlan("Sample: David Tan (MY)", false);
  const jane = person("Jane Lim", "ex-spouse");
  const ryan = person("Ryan Tan", "son");
  const chloe = person("Chloe Tan", "daughter", { minor: true });
  const father = person("Tan Ah Kow", "father");
  plan.people = [jane, ryan, chloe, father];
  plan.liabilities = 45000;
  plan.checklist = {
    ...plan.checklist,
    will: item("done", "Drawn up before the divorce. Never updated since.", "2019-05-10"),
    executor: item("done", "A sibling agreed to act."),
    insurance: item("done", "Nominee is still the ex-wife — not yet updated.", "2018-07-01"),
    retirement: item("done", "EPF nominee is still the ex-wife — not yet updated.", "2017-03-01"),
    joint: item("todo"),
    inventory: item("in_progress"),
    business_trust: item("na"),
    business_agreement: item("na"),
  };
  plan.will = [
    { personId: jane.id, pct: 50 },
    { personId: ryan.id, pct: 25 },
    { personId: chloe.id, pct: 25 },
  ];
  plan.willChecks = { twoWitnesses: "yes", ageOk: "yes" };
  plan.assets = [
    asset("Family home", "property", 650000, "estate"),
    asset("Savings", "cash", 80000, "estate"),
    asset("ASB units", "investments", 60000, "estate"),
    asset("EPF (Account 1 and 2)", "retirement", 220000, "nominee", [{ personId: jane.id, pct: 100 }]),
    asset("Life insurance", "insurance", 250000, "nominee", [{ personId: jane.id, pct: 100 }]),
    asset("Sole proprietorship", "business", 300000, "estate", [], undefined, undefined, { businessType: "sole" }),
  ];
  plan.advisorNotes =
    "Sample data for demonstration only. Divorced in 2022, but the will, EPF nomination and life insurance nomination were never updated and still name the ex-spouse — none of these are automatically changed by a divorce in Malaysia.";
  return plan;
}

export const samplePlans = () => [sampleAhmad(), sampleDavid(), sampleMichelle()];
