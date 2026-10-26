// Financial Services Act 2013, Schedule 10 — who holds a Malaysian life insurance payout personally.
//
//  - spouse, child: automatic trust (para 5). Outside the estate, outside the will, creditor-proof.
//  - parent: trust only if there was no spouse or child when the nomination was made.
//  - anyone else: receives the money as executor for the estate (para 6), unless the benefits were assigned to them in writing.
//
// Trust status is fixed on the date of nomination, not the date of death (Estate Planning Scenarios, scenario 5):
// a parent nominated before the marriage stays a trust nominee, a parent nominated after it does not,
// and a fiancé(e) nominated before the marriage does not qualify even after marrying.

export function usesMalaysianNomineeRules(plan, asset) {
  return asset.category === "insurance" && asset.route === "nominee" && !asset.foreign && !plan.muslim;
}

export function classifyNominee(person, plan, asset) {
  const rel = person.relationship;
  const nominatedOn = asset?.nominatedOn;
  const datesKnown = !!(nominatedOn && plan.marriedOn);
  const beforeMarriage = datesKnown && nominatedOn < plan.marriedOn;

  if (rel === "son" || rel === "daughter") return { kind: "trust", reason: "child" };

  if (rel === "spouse") {
    return beforeMarriage
      ? { kind: "non-trust", reason: "was nominated before the marriage, and a fiancé(e) does not qualify for a trust nomination" }
      : { kind: "trust", reason: "spouse" };
  }

  if (rel === "ex-spouse") {
    return { kind: "trust", reason: "former spouse \u2014 shown as receiving it, as in the original version; confirm with the insurer" };
  }

  if (rel === "father" || rel === "mother") {
    if (datesKnown) {
      return beforeMarriage
        ? { kind: "trust", reason: "parent nominated before the marriage, when there was no spouse" }
        : { kind: "non-trust", reason: "was nominated after the marriage, and a parent only qualifies when there is no spouse or child at the time of nomination" };
    }
    const blocked = plan.people.some((p) => p.alive && (p.relationship === "spouse" || p.relationship === "son" || p.relationship === "daughter"));
    return blocked
      ? { kind: "non-trust", reason: "is a parent, and a parent only holds a trust nomination when there is no spouse or child (add the nomination date to check when it was made)" }
      : { kind: "trust", reason: "parent, with no spouse or child" };
  }

  return { kind: "non-trust", reason: "is not a spouse, child or parent" };
}
