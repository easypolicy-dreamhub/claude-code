export const REL_LABEL = {
  spouse: "Spouse",
  "ex-spouse": "Ex-spouse",
  son: "Son",
  daughter: "Daughter",
  father: "Father",
  mother: "Mother",
  grandchild: "Grandchild",
  brother: "Brother",
  sister: "Sister",
  other: "Other (friend, charity, trust)",
};

export const REL_SHORT = { ...REL_LABEL, other: "Other" };

export const RELATIONSHIPS = Object.keys(REL_LABEL);

export const CATEGORY_LABEL = {
  property: "Property",
  cash: "Cash and deposits",
  investments: "Investments",
  insurance: "Insurance / takaful",
  retirement: "CPF / EPF / retirement",
  business: "Business interest",
  other: "Other",
};

export const ROUTE_LABEL = {
  estate: "Through the estate (will or default law)",
  nominee: "Nominee / beneficiary designation",
  joint: "Joint ownership",
  trust: "Held in a trust",
  hibah: "Completed lifetime gift (hibah)",
  foundation: "Held via a foundation / family office",
};

export const ROUTE_SUFFIX = { nominee: "nominee", trust: "trust", hibah: "hibah" };

export const NOMINATION_LABEL = {
  trust: "Trust nomination (spouse or children only)",
  revocable: "Revocable nomination",
};

export const BUSINESS_TYPE_LABEL = {
  sole: "Sole proprietorship",
  partnership: "Partnership",
  sdn: "Sdn Bhd (private limited company)",
  holdco: "Investment holding company",
};

export const BUSINESS_TYPE_HINT = {
  sole: "A sole proprietor can will away the business assets, but not the business itself. The SSM registration must be closed on death.",
  partnership:
    "Under the Partnership Act a partnership is dissolved on a partner's death unless the partnership agreement says otherwise. Only the business interest can pass to heirs.",
  holdco:
    "Only the shares pass on death; the properties and investments stay in the company, so there is no separate transfer for each one. Make sure another director can act and the constitution covers a shareholder's death, otherwise the company can freeze until probate is granted.",
  sdn: "Only the deceased's shares pass under the will. The company's assets belong to the company, not the shareholder, and cannot be given away as personal estate.",
};

export const STATE_LABEL = {
  peninsular: "Peninsular Malaysia",
  sarawak: "Sarawak",
  sabah: "Sabah",
};

export const STATUS_LABEL = { done: "Done", in_progress: "In progress", todo: "To do", na: "N/A" };

export const personLabel = (p) =>
  `${p.name.trim() || "Unnamed"} (${p.relationship === "other" ? "other" : REL_LABEL[p.relationship].toLowerCase()})`;

export const personName = (p) => p.name.trim() || REL_SHORT[p.relationship];
