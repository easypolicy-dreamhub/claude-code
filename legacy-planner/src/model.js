import { uid } from "./format.js";

export const GROUPS = [
  "Core documents",
  "Beneficiary designations",
  "Trusts and structures",
  "If they lose capacity",
  "Legacy and housekeeping",
];

const CHECKLIST_IDS = [
  "will", "executor", "guardian", "insurance", "retirement", "joint", "living_trust", "testamentary_trust",
  "business_trust", "business_agreement", "foundation", "foreign_assets", "hibah", "charity", "poa", "amd",
  "inventory", "digital", "letter", "review",
];

function blankChecklist() {
  const out = {};
  CHECKLIST_IDS.forEach((id) => (out[id] = { status: "todo", notes: "" }));
  return out;
}

export function checklistItems(muslim) {
  const items = [
    {
      id: "will",
      group: "Core documents",
      title: muslim ? "Wasiat (Islamic will)" : "Will",
      why: muslim
        ? "Optional. Covers up to one-third of the net estate for people who are not faraid heirs (for example a grandchild, carer or wakaf). The rest follows faraid unless every heir consents. Record the beneficiaries in the Assets tab."
        : 'Directs who receives assets that pass through the estate, names the executor and any guardians. Without one, the Distribution Act 1958 decides. Marriage automatically revokes an earlier will unless it says it was made in contemplation of that marriage — divorce does not revoke it at all, so a gift to a former spouse stands exactly as written until the will is updated. Record the beneficiaries in the Assets tab, the marriage date in the Profile tab if that applies, and mark a former spouse as "Ex-spouse" there so the app can flag it if they are still named. A valid will needs a writer aged 18 or over (21 in Sabah) of sound mind, in writing, signed in front of two witnesses present together — and no witness (or witness’s spouse) may be a beneficiary, or that gift is void. Note that a Malaysian will may not, by itself, effectively pass assets physically located in another country — see "Foreign assets" below. A will only works if it can be found: keep the original where the executor can reach it and tell them where. Do not keep the only original in a bank safe deposit box — the bank seals the box on death, and probate needs the original will.',
    },
    {
      id: "executor",
      group: "Core documents",
      title: muslim ? "Executor / wasi named, with a back-up" : "Executor named, with a back-up",
      why: "Someone willing and able to administer the estate, or a professional / trust company. Confirm they know they were chosen. If a sole executor dies after probate is granted, their own executor takes over — someone the deceased never chose — so name a back-up or consider a corporate executor.",
    },
    {
      id: "guardian",
      group: "Core documents",
      title: "Guardian for minor children",
      why: "Name a guardian in the will (Guardianship of Infants Act 1961) and consider a trust to hold assets for minors, who cannot give a valid receipt. Without a named guardian the court decides, which is slow. The guardian cares for the child but does not control trust-nominated insurance money: that is held by the surviving parent, or by Amanah Raya if both parents have died. Mark N/A if there are no minor children.",
    },
    {
      id: "insurance",
      group: "Beneficiary designations",
      title: muslim ? "Takaful / life insurance nominations" : "Life insurance nominations",
      why: 'Nominations sit outside the will. Under the Financial Services Act 2013, Schedule 10, a nominated spouse, child or parent (a parent only if there is no living spouse or child) holds the money on an automatic trust: it bypasses the estate, cannot be overridden by the will and is safe from creditors. Any other nominee (a sibling, friend, partner who is not a spouse) receives the money as executor for the estate, not for themselves — unless the policy owner assigns the benefits to them in writing. If one of several nominees dies first the survivors share the whole sum; if all die first it falls into the estate. Trust status is fixed on the day of nomination: a parent nominated before marriage stays a trust nominee, but a parent nominated after marriage, or a fianc\u00e9(e) nominated before it, only holds the money for the estate \u2014 so renominate after marrying. A minor child\u2019s share is paid to the surviving parent as statutory trustee, or to Amanah Raya if no parent survives. The insurer pays according to the latest valid nomination, so review it after a marriage, divorce, birth or death. If a policy was bought in Singapore while working there, mark it "Held outside Malaysia" in the Assets tab — Singapore’s Insurance Act treats a trust nomination differently (valid only for a spouse or children) from a Malaysian one.',
    },
    {
      id: "retirement",
      group: "Beneficiary designations",
      title: "EPF nomination",
      why: `EPF savings pass to the nominees on record under the EPF Act, not by the will. If a nominee dies first their share goes into the estate, and if every nominee dies first the whole balance does — so review the nomination after any death in the family.${muslim ? " For Muslims, confirm how nominees hold the funds relative to faraid." : ""} Unlike CPF in Singapore, an EPF nomination is not automatically cancelled by marriage or divorce — members must update it themselves. Review after marriage, birth or divorce. If they also have Singapore CPF savings from working there, record it as a separate asset and mark it "Held outside Malaysia" — CPF follows different rules (marriage automatically revokes a CPF nomination; EPF does not).`,
    },
    {
      id: "joint",
      group: "Beneficiary designations",
      title: "Jointly-owned assets reviewed",
      why: "The deceased’s share of a joint account or property (for example 1/3 where there are three holders) generally forms part of the estate and needs the grant to release it — joint accounts are not freely usable estate cash, and surviving holders should not withdraw the money. Only where the title or account mandate expressly gives survivorship does the share pass straight to the survivor. Check how each one is held.",
    },
    {
      id: "living_trust",
      group: "Trusts and structures",
      title: "Living (inter vivos) trust",
      why: "Holds assets during life and after death. Can avoid probate delay, add conditions, and protect minors or vulnerable beneficiaries.",
    },
    {
      id: "testamentary_trust",
      group: "Trusts and structures",
      title: "Testamentary trust (created by the will)",
      why: "A trust set out inside the will itself, which only comes into being on death — unlike a living trust, nothing needs to be transferred or funded, and there are no lifetime trustee fees. Commonly used to hold a minor child's or dependant's inheritance and release it in stages (for example at 21, 25 and 30) instead of all at once. Mark N/A if not applicable.",
    },
    {
      id: "business_trust",
      group: "Trusts and structures",
      title: "Business trust / holding structure for succession",
      why: "A trust or holding company that owns the business interests, so ownership and control pass as intended instead of being split among heirs. Mark N/A if there is no business.",
    },
    {
      id: "business_agreement",
      group: "Trusts and structures",
      title: "Shareholder / partnership agreement and key-person cover",
      why: "Buy-sell terms, who steps in, and funding for a buy-out. A partnership is dissolved on a partner’s death unless the agreement says otherwise. Mark N/A if there is no business.",
    },
    {
      id: "foundation",
      group: "Trusts and structures",
      title: "Foundation / family office structure reviewed",
      why: "For substantial or business wealth, assets may be held through a private foundation (for example a Labuan Foundation under the Labuan Foundations Act 2010, or its Shariah-compliant equivalent) or coordinated through a family office. These are governed by their own charter, not the will or default law — confirm how the structure coordinates with the rest of the estate plan. If wealth was built up while working in Singapore, a Singapore single family office (for example under the Section 13O or 13U tax schemes) may also be relevant. Mark N/A if not applicable. Record the holding in the Assets tab as “Held via a foundation / family office.”",
    },
    {
      id: "foreign_assets",
      group: "Trusts and structures",
      title: "Foreign assets and cross-border probate reviewed",
      why: 'It is common for Malaysians who have worked abroad — most often in Singapore — to hold CPF savings, a bank or brokerage account, an insurance policy, or even property there. Mark each one "Held outside Malaysia" in the Assets tab so it is checked separately. A Malaysian grant of probate generally does not, by itself, let the executor deal with assets in another country — a separate grant (or resealing of the Malaysian grant) is usually needed there. Property located abroad is normally governed by that country\'s own succession law, not Malaysia\'s, regardless of the Malaysian will. Mark N/A if they have no assets outside Malaysia.',
    },
  ];

  if (muslim) {
    items.push({
      id: "hibah",
      group: "Trusts and structures",
      title: "Hibah (lifetime gifts) documented",
      why: "A completed gift during life can pass outside faraid. It needs clear intent, acceptance and proper documentation, and can be challenged if it looks like a disguised will.",
    });
    items.push({
      id: "charity",
      group: "Trusts and structures",
      title: "Wakaf / sadaqah jariah (optional)",
      why: "Endowments can be made in life, or by wasiat within the one-third limit.",
    });
  } else {
    items.push({
      id: "charity",
      group: "Trusts and structures",
      title: "Charitable giving or bequests (optional)",
      why: "Legacy gifts to charities or causes, by will or by lifetime gift.",
    });
  }

  items.push(
    {
      id: "poa",
      group: "If they lose capacity",
      title: "Power of attorney (with enduring provisions)",
      why: "A general power of attorney may stop working if they lose capacity. Confirm with a lawyer that the instrument survives loss of capacity.",
    },
    {
      id: "amd",
      group: "If they lose capacity",
      title: "Advance healthcare wishes",
      why: "Written treatment preferences. Malaysia has no dedicated statute for these, so confirm the current position and share the wishes with family and doctors.",
    },
    {
      id: "inventory",
      group: "Legacy and housekeeping",
      title: "Asset and liability inventory is current",
      why: "Use the Assets tab. Include how each asset is held and who is named. An asset missed before probate means a second grant, double the legal fees and years of delay, so trace every bank account, policy and investment — including dormant accounts and safe deposit boxes.",
    },
    {
      id: "digital",
      group: "Legacy and housekeeping",
      title: "Digital assets and account access",
      why: "Email, cloud storage, crypto, online banking and social accounts. Name an individual (not a company) as digital facilitator and record where credentials are safely kept, not the passwords themselves.",
    },
    {
      id: "letter",
      group: "Legacy and housekeeping",
      title: "Letter of wishes / legacy message",
      why: "Non-binding note explaining the reasons for decisions, personal items, funeral wishes and messages to family.",
    },
    {
      id: "review",
      group: "Legacy and housekeeping",
      title: "Plan reviewed in the last 12 months",
      why: "Review after marriage, divorce, birth, death, a business change, a move abroad, or a change in the law.",
    },
  );

  return items;
}

export function checklistProgress(plan) {
  const items = checklistItems(plan.muslim);
  const counted = items.filter((i) => plan.checklist[i.id]?.status !== "na");
  const done = counted.filter((i) => plan.checklist[i.id]?.status === "done").length;
  return { total: counted.length, done, items };
}

export function newPlan(clientName, muslim) {
  return {
    id: uid(),
    clientName,
    jurisdiction: "MY",
    state: "peninsular",
    muslim,
    clientGender: "male",
    nomineeHeldForHeirs: true,
    people: [],
    checklist: blankChecklist(),
    assets: [],
    liabilities: 0,
    adminCostEstimate: 0,
    will: [],
    willChecks: {},
    voidWillIds: [],
    marriedOn: undefined,
    willMadeInContemplationOfMarriage: false,
    advisorNotes: "",
    updatedAt: new Date().toISOString(),
  };
}

// Brings an older saved plan up to the current shape.
export function normalizePlan(raw) {
  const base = newPlan(raw.clientName ?? "Profile", !!raw.muslim);
  return {
    ...base,
    ...raw,
    id: raw.id ?? base.id,
    jurisdiction: "MY",
    checklist: { ...base.checklist, ...(raw.checklist ?? {}) },
    people: raw.people ?? [],
    assets: raw.assets ?? [],
    will: raw.will ?? [],
    willChecks: raw.willChecks ?? {},
    voidWillIds: raw.voidWillIds ?? [],
  };
}

export const willIsDone = (plan) => plan.checklist.will?.status === "done";

export const jointHolderCount = (asset) => Math.max(1, Math.round(Number(asset.jointHolders) || 2));

// The part of an asset that belongs to the deceased. A joint asset is split equally between its holders.
export function deceasedValue(asset) {
  const value = Math.max(0, Number(asset.value) || 0);
  return asset.route === "joint" ? value / jointHolderCount(asset) : value;
}
