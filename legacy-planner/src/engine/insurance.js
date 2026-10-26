// Financial Services Act 2013, Schedule 10 — who holds a Malaysian life insurance payout personally.
//
//  - spouse, child: automatic trust (para 5). Outside the estate, outside the will, creditor-proof.
//  - parent: trust only if there is no living spouse or child.
//  - anyone else: receives the money as executor for the estate (para 6), unless the benefits were assigned to them in writing.

export function usesMalaysianNomineeRules(plan, asset) {
  return asset.category === "insurance" && asset.route === "nominee" && !asset.foreign && !plan.muslim;
}

export function classifyNominee(person, people) {
  const rel = person.relationship;
  if (rel === "spouse" || rel === "son" || rel === "daughter") return { kind: "trust", reason: "spouse or child" };
  if (rel === "father" || rel === "mother") {
    const blocked = people.some((p) => p.alive && (p.relationship === "spouse" || p.relationship === "son" || p.relationship === "daughter"));
    return blocked
      ? { kind: "non-trust", reason: "a parent only holds a trust nomination when there is no living spouse or child" }
      : { kind: "trust", reason: "parent, with no living spouse or child" };
  }
  if (rel === "ex-spouse") return { kind: "non-trust", reason: "a former spouse is not a spouse, child or parent" };
  return { kind: "non-trust", reason: "not a spouse, child or parent" };
}
