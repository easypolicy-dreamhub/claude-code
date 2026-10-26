import { fraction } from "../format.js";

const isChild = (p) => p.relationship === "son" || p.relationship === "daughter";

// Each entry is one "stock" of issue: a living child on their own, or the living grandchildren of a child who has died.
function issueGroups(people) {
  const groups = [];
  for (const child of people.filter(isChild)) {
    if (child.alive) {
      groups.push([child.id]);
    } else {
      const grandchildren = people.filter((p) => p.relationship === "grandchild" && p.alive && p.parentId === child.id);
      if (grandchildren.length) groups.push(grandchildren.map((g) => g.id));
    }
  }
  return groups;
}

// Distribution Act 1958 (non-Muslims, Peninsular Malaysia and Sarawak).
export function distributionAct(people) {
  const law = "Distribution Act 1958";
  const notes = [];
  const alive = people.filter((p) => p.alive);
  const spouses = alive.filter((p) => p.relationship === "spouse");
  const parents = alive.filter((p) => p.relationship === "father" || p.relationship === "mother");
  const siblings = alive.filter((p) => p.relationship === "brother" || p.relationship === "sister");
  const groups = issueGroups(people);

  const hasSpouse = spouses.length > 0;
  const hasIssue = groups.length > 0;
  const hasParents = parents.length > 0;

  if (spouses.length > 1) {
    notes.push("More than one spouse is recorded. Non-Muslim law recognises one spouse; the spouse share is split equally here, so please review.");
  }

  let spouseShare = 0;
  let issueShare = 0;
  let parentShare = 0;
  let siblingShare = 0;

  if (hasSpouse && hasIssue && hasParents) {
    spouseShare = 0.25;
    issueShare = 0.5;
    parentShare = 0.25;
  } else if (hasSpouse && hasIssue) {
    spouseShare = 1 / 3;
    issueShare = 1 - spouseShare;
  } else if (hasSpouse && hasParents) {
    spouseShare = 0.5;
    parentShare = 0.5;
  } else if (hasSpouse) {
    spouseShare = 1;
  } else if (hasIssue && hasParents) {
    issueShare = 2 / 3;
    parentShare = 1 / 3;
  } else if (hasIssue) {
    issueShare = 1;
  } else if (hasParents) {
    parentShare = 1;
  } else if (siblings.length) {
    siblingShare = 1;
  } else {
    notes.push("No spouse, issue, parents or siblings are recorded. More remote relatives (grandparents, uncles and aunts and so on), or the Government, would take. This is not modelled.");
  }

  if (hasParents) {
    if (hasSpouse && hasIssue) {
      notes.push("Spouse, children and a surviving parent all inherit together under the Distribution Act 1958: spouse 1/4, children 1/2 combined, parent(s) 1/4 combined.");
    } else if (hasIssue) {
      notes.push("No surviving spouse: children and a surviving parent inherit together under the Distribution Act 1958 — children 2/3 combined, parent(s) 1/3 combined.");
    }
  }

  const shares = [];
  const spouseBasis = hasIssue && hasParents
    ? `${fraction(spouseShare)} to spouse (with issue and parents)`
    : hasIssue
      ? `${fraction(spouseShare)} to spouse (with issue)`
      : hasParents
        ? "1/2 to spouse (with parents, no issue)"
        : "whole estate to spouse";
  spouses.forEach((s) => shares.push({ personId: s.id, fraction: spouseShare / spouses.length, basis: spouseBasis }));

  groups.forEach((group) => {
    const groupShare = issueShare / groups.length;
    group.forEach((id) =>
      shares.push({
        personId: id,
        fraction: groupShare / group.length,
        basis: hasSpouse ? `issue share of ${fraction(issueShare)}, equally` : "issue share, equally",
      }),
    );
  });

  parents.forEach((p) => shares.push({ personId: p.id, fraction: parentShare / parents.length, basis: "parents share, equally" }));
  if (siblingShare) {
    siblings.forEach((s) => shares.push({ personId: s.id, fraction: siblingShare / siblings.length, basis: "siblings share, equally" }));
  }

  if (hasIssue && people.some((p) => p.relationship === "grandchild" && p.alive && !people.find((x) => x.id === p.parentId))) {
    notes.push("A grandchild has no parent selected, so they are not included in the calculation.");
  }

  return { law, shares: shares.filter((s) => s.fraction > 0), notes };
}
