import { money } from "../format.js";
import { BUSINESS_TYPE_HINT } from "../labels.js";

const ORDER = { high: 0, medium: 1, info: 2 };

// The "Gaps and things to check" list. level: high = act, medium = check, info = note.
export function buildFlags(plan, current, defaultLaw, willInPlace) {
  const flags = [];
  const add = (level, text) => flags.push({ level, text });
  const byId = new Map(plan.people.map((p) => [p.id, p]));
  const done = (id) => plan.checklist[id]?.status === "done";
  const doc = plan.muslim ? "wasiat" : "will";
  const nameOf = (p) => p.name.trim() || "A person";

  // --- Will and default law -------------------------------------------------
  if (current.netEstate > 0 && !willInPlace) {
    add(
      "high",
      plan.muslim
        ? `No wasiat is in place. The ${money(current.netEstate)} net estate follows faraid exactly, with no room for a gift to non-heirs (for example a wakaf, a grandchild or a carer).`
        : `No will is in place. The ${money(current.netEstate)} net estate follows the ${current.law}, not their wishes.`,
    );
  }
  if (willInPlace && current.netEstate > 0 && plan.will.length === 0) {
    add("medium", `The ${doc} is marked done but no beneficiaries are recorded in the Assets tab, so the outcome shows the default law. Add the ${doc} beneficiaries to see the real result.`);
  }
  if (willInPlace && !plan.muslim && plan.will.length) {
    const total = plan.will.reduce((sum, g) => sum + g.pct, 0);
    if (Math.abs(total - 100) > 0.01) {
      add("medium", `Will beneficiaries total ${total.toFixed(1)}% rather than 100%. ${total < 100 ? "The balance falls into intestacy." : "Shares are scaled down proportionally."}`);
    }
  }
  current.notes.filter((n) => n.startsWith("Wasiat")).forEach((n) => add("medium", n));

  // --- Where the law applies, and will validity (non-Muslim) -----------------
  if (!plan.muslim && plan.state === "sabah") {
    add("high", "The Distribution Act 1958 applies in Peninsular Malaysia and Sarawak, not Sabah. The default-law figures in this app may be wrong for a Sabah estate — confirm the rules that apply with a Sabah lawyer. (Also, the minimum age to make a will in Sabah is 21.)");
  }
  if (willInPlace && !plan.muslim) {
    const checks = plan.willChecks ?? {};
    if (checks.twoWitnesses === "no") {
      add("high", "The will was not signed in front of two witnesses present together. Under the Wills Act 1959 it may not be valid — have a lawyer check it, and consider making a new one.");
    }
    if (checks.ageOk === "no") {
      add("high", `The will writer was under ${plan.state === "sabah" ? 21 : 18} when they signed. Under the Wills Act 1959 a will must be made by someone aged 18 or over (21 in Sabah), so it may not be valid.`);
    }
    (plan.voidWillIds ?? []).forEach((id) => {
      const p = byId.get(id);
      if (p) {
        add("high", `${nameOf(p)} (or their spouse) witnessed the will and is also a beneficiary. Under the Wills Act 1959 that gift is void, and the share falls into intestacy. Re-sign the will with independent witnesses.`);
      }
    });
  }

  // --- Cash needed to start administering the estate -------------------------
  const transferCost = current.estateProperty * 0.01;
  const adminCost = Math.max(0, Number(plan.adminCostEstimate) || 0);
  const needed = Math.max(0, plan.liabilities || 0) + transferCost + adminCost;
  if (current.estatePool > 0 && needed > 0 && current.liquidCash < needed - 0.5) {
    add(
      "high",
      `The estate may not have enough cash to start. Cash, investments and insurance paid to the estate: ${money(current.liquidCash)}. Needed up front: debts ${money(plan.liabilities || 0)} + property transfer fees of about 1% (${money(transferCost)})${adminCost ? ` + estimated administration costs ${money(adminCost)}` : ""} = ${money(needed)}. Shortfall about ${money(needed - current.liquidCash)}. Nothing can be distributed until these are paid, and a will does not provide cash — consider life cover or a cash reserve. This is an estimate only.`,
    );
  }

  // --- What passes outside the will ------------------------------------------
  if (current.outsideEstate > 0 && current.totalAssets > 0) {
    const share = (current.outsideEstate / current.totalAssets) * 100;
    add("info", `${share.toFixed(0)}% of assets (${money(current.outsideEstate)}) pass outside the ${doc} by nomination, joint ownership, trust or hibah. A ${doc} does not control these, so keep the designations up to date.`);
  }

  // --- Minors and dependants -------------------------------------------------
  current.people
    .filter((p) => p.person.minor && p.total > 0)
    .forEach((p) => {
      if (!done("living_trust") && !done("testamentary_trust")) {
        add("high", `${p.person.name} is a minor and would receive ${money(p.total)}. Minors cannot give a valid receipt, so a court-supervised guardian or trustee may be needed. Consider a living trust or a testamentary trust (set up in the will) and name a guardian.`);
      }
    });
  plan.people
    .filter((p) => p.alive && p.relationship === "spouse")
    .forEach((spouse) => {
      const received = current.people.find((p) => p.person.id === spouse.id)?.total ?? 0;
      if (current.totalAssets > 0 && received === 0) add("high", `${spouse.name} (spouse) would receive nothing under the current plan.`);
    });
  current.people
    .filter((p) => p.person.relationship !== "other")
    .forEach((p) => {
      const underDefault = defaultLaw.people.find((x) => x.person.id === p.person.id)?.total ?? 0;
      if (willInPlace && underDefault > 0.005 && p.total < underDefault * 0.5 && underDefault - p.total > 1) {
        add(
          "medium",
          `${p.person.name} receives ${money(p.total)} under the plan against ${money(underDefault)} under the default law. ${plan.muslim ? "Confirm the other heirs consent to this." : "Excluded or under-provided dependants may apply to court for reasonable provision."}`,
        );
      }
    });

  // --- Business -----------------------------------------------------------------
  if (
    plan.assets.some((a) => a.category === "business" && a.route === "estate" && a.value > 0) &&
    !done("business_trust") &&
    !done("business_agreement")
  ) {
    add("medium", "Business interests sit in the estate with no succession trust or shareholder / partnership agreement recorded. Control may be split among heirs and delayed by probate.");
  }
  plan.assets
    .filter((a) => a.category === "business" && a.value > 0 && a.businessType)
    .forEach((a) => add("info", `"${a.name || "Business interest"}": ${BUSINESS_TYPE_HINT[a.businessType]}`));

  // --- Assets that bypass or miss a nomination --------------------------------
  plan.assets
    .filter((a) => (a.category === "insurance" || a.category === "retirement") && a.route === "estate" && a.value > 0)
    .forEach((a) => add("medium", `"${a.name}" is routed to the estate. Check whether a nomination is in place; without one it may be delayed or fall under the default law.`));

  // Malaysian insurance nominees who are not a spouse, child or parent hold the money for the estate.
  current.nonTrustNominees
    .filter((n) => n.person.relationship !== "ex-spouse")
    .forEach((n) => {
      add(
        "high",
        `${nameOf(n.person)} is nominated on "${n.asset.name}" but ${n.reason}. Under the Financial Services Act 2013 (Schedule 10) they receive the money as executor for the estate, not personally, and must pass it on under the will or the Distribution Act — creditors can reach it too. To let them keep it, nominate a spouse, child or parent, or assign the policy benefits to them in writing.`,
      );
    });

  if (plan.assets.some((a) => a.route === "trust" && a.value > 0) && !done("living_trust") && !done("business_trust")) {
    add("medium", "Some assets are marked as held in trust, but no living trust or business trust is marked as done in the checklist.");
  }
  plan.assets
    .filter((a) => ["nominee", "trust", "hibah"].includes(a.route) && !(a.route === "nominee" && plan.muslim && plan.nomineeHeldForHeirs))
    .forEach((a) => {
      const total = a.allocations.reduce((sum, x) => sum + x.pct, 0);
      if (a.value > 0 && Math.abs(total - 100) > 0.01) add("medium", `Beneficiaries for "${a.name}" total ${total.toFixed(1)}% rather than 100%.`);
    });

  // Joint ownership: the deceased's share is part of the estate unless survivorship is set out.
  current.jointEstateShares.forEach(({ asset, share }) => {
    add(
      "medium",
      `"${asset.name || "Joint asset"}" is jointly held. The deceased's share (${money(share)}) is treated as part of the estate and needs the grant to release it. The other holders should not withdraw from a joint account after a death, and it is not freely usable estate cash. If the title or mandate expressly gives survivorship, tick that on the asset.`,
    );
  });

  const foundations = plan.assets.filter((a) => a.route === "foundation" && a.value > 0);
  if (foundations.length && !done("foundation")) {
    add("medium", `Assets are held via a foundation or family office (${foundations.map((a) => a.name).join(", ")}), but the checklist item for reviewing that structure isn't marked done.`);
  }

  // --- Assets held outside Malaysia ---------------------------------------------
  const foreign = plan.assets.filter((a) => a.foreign && a.value > 0);
  if (foreign.length) {
    if (!done("foreign_assets")) {
      add(
        "medium",
        `${money(foreign.reduce((sum, a) => sum + a.value, 0))} is held outside Malaysia (${foreign.map((a) => a.name).join(", ")}), but the checklist item for reviewing foreign assets and cross-border probate isn't marked done. A Malaysian grant of probate generally does not, by itself, let the executor deal with assets in another country.`,
      );
    }
    const property = foreign.filter((a) => a.category === "property");
    if (property.length) {
      add(
        "medium",
        `${property.map((a) => a.name).join(", ")} ${property.length > 1 ? "are" : "is"} located outside Malaysia. Property is normally governed by the succession law of the country where it sits, not Malaysia's Distribution Act or faraid, regardless of the Malaysian will — confirm this with a lawyer qualified in that country.`,
      );
    }
  }
  plan.assets
    .filter((a) => a.category === "insurance" && a.route === "nominee" && a.foreign && a.nominationType === "trust")
    .forEach((a) => {
      a.allocations
        .filter((x) => x.pct > 0)
        .forEach((x) => {
          const p = byId.get(x.personId);
          if (p && p.relationship !== "spouse" && p.relationship !== "son" && p.relationship !== "daughter") {
            add("high", `${p.name.trim() || "A beneficiary"} is named under a trust nomination on "${a.name}", but Singapore's Insurance Act only allows a trust nomination in favour of a spouse or children. This may not create a valid trust — confirm with the insurer or a lawyer.`);
          }
        });
    });

  // --- Marriage dates ---------------------------------------------------------
  if (plan.marriedOn && plan.people.some((p) => p.relationship === "spouse" && p.alive)) {
    if (!plan.muslim) {
      const will = plan.checklist.will;
      if (will?.status === "done" && will.date && will.date < plan.marriedOn && !plan.willMadeInContemplationOfMarriage) {
        add("high", `The will was signed on ${will.date}, before the marriage on ${plan.marriedOn}. Marriage automatically revokes an earlier will unless it says it was made in contemplation of that marriage — confirm this will is still valid, or have it renewed.`);
      }
    }
    const cpf = plan.assets.filter((a) => a.category === "retirement" && a.foreign && a.value > 0);
    const retirement = plan.checklist.retirement;
    if (cpf.length && retirement?.status === "done" && retirement.date && retirement.date < plan.marriedOn) {
      const amount = cpf.reduce((sum, a) => sum + a.value, 0);
      add(
        "high",
        `The retirement nomination was last reviewed on ${retirement.date}, before the marriage on ${plan.marriedOn}. For the Singapore CPF savings specifically (${cpf.map((a) => a.name).join(", ")}, ${money(amount)}), marriage automatically revokes an existing CPF nomination (this is different from EPF, which is not automatically revoked) — that nomination is very likely no longer valid. The figures above still show this amount going to the old nominee because a new nomination has not been confirmed; treat that ${money(amount)} as unresolved and pending a fresh CPF nomination, not as secured.`,
      );
    }
    const foreignPolicy = plan.assets.some((a) => a.category === "insurance" && a.foreign && a.value > 0);
    const insurance = plan.checklist.insurance;
    if (insurance?.status === "done" && insurance.date && insurance.date < plan.marriedOn) {
      add(
        "medium",
        `The ${plan.muslim ? "takaful / life insurance" : "life insurance"} nomination was made on ${insurance.date}, before the marriage on ${plan.marriedOn}.${foreignPolicy ? " For a Singapore policy, marriage does not automatically cancel an insurance nomination there (unlike a will or CPF nomination), so an out-of-date nomination stays in force silently until it is changed." : ""} Review the nomination to confirm the named nominee is still who they want.`,
      );
    }
  }

  // --- Former spouses --------------------------------------------------------
  const exSpouses = plan.people.filter((p) => p.relationship === "ex-spouse");
  if (exSpouses.length) {
    const exIds = new Set(exSpouses.map((p) => p.id));
    const names = (list) => list.some((x) => exIds.has(x.personId) && x.pct > 0);
    if (willInPlace && names(plan.will)) {
      add("high", `The ${doc} still names an ex-spouse as a beneficiary. Divorce does not automatically revoke a gift to a former spouse in Malaysia — the gift stands exactly as written until the ${doc} is updated.`);
    }
    plan.assets
      .filter((a) => a.category === "retirement" && a.route === "nominee" && names(a.allocations))
      .forEach((a) =>
        add(
          "high",
          a.foreign
            ? `"${a.name}" is still nominated to an ex-spouse. Divorce does not automatically cancel a Singapore CPF nomination (only marriage does) — a new nomination is needed to remove them.`
            : `"${a.name}" is still nominated to an ex-spouse. An EPF nomination is not automatically cancelled by divorce — confirm the current position with EPF and resubmit the nomination if this is not intended.`,
        ),
      );
    plan.assets
      .filter((a) => a.category === "insurance" && a.route === "nominee" && names(a.allocations))
      .forEach((a) =>
        add(
          "high",
          a.foreign
            ? `"${a.name}" is still nominated to an ex-spouse. Divorce does not automatically cancel a life insurance nomination in Singapore, whether trust or revocable — update it with the insurer if this is not intended.`
            : plan.muslim
              ? `"${a.name}" is still nominated to an ex-spouse. Confirm with the insurer or takaful operator whether the nomination has been updated since the divorce.`
              : `"${a.name}" is still nominated to an ex-spouse. Divorce does not remove the nomination, but a former spouse is not a spouse under the Financial Services Act 2013 (Schedule 10), so they would most likely receive the money as executor for the estate rather than keep it. Confirm with the insurer and update the nomination if this is not intended.`,
        ),
      );
    plan.assets
      .filter((a) => a.route === "joint" && a.allocations.some((x) => exIds.has(x.personId)))
      .forEach((a) => add("medium", `"${a.name}" is still recorded as jointly held with an ex-spouse. Confirm whether this was dealt with as part of the divorce settlement.`));
    plan.assets
      .filter((a) => (a.route === "trust" || a.route === "hibah") && names(a.allocations))
      .forEach((a) => add("medium", `"${a.name}" still allocates a share to an ex-spouse. Review whether this is still intended.`));
  }

  // --- Muslim profiles ------------------------------------------------------------
  if (plan.muslim) {
    const nominees = plan.assets.filter((a) => a.route === "nominee" && a.value > 0);
    if (nominees.length && plan.nomineeHeldForHeirs) {
      add("info", `Nominee assets (${nominees.map((a) => a.name).join(", ")}) are treated as held for the faraid heirs. To pass them differently, document a hibah and set the route to Hibah.`);
    }
    if (nominees.length && !plan.nomineeHeldForHeirs) {
      add("medium", "Nominations are being followed exactly as recorded. Confirm with a Syariah adviser that the nominee may keep the proceeds rather than hold them for the heirs.");
    }
    if (plan.assets.some((a) => a.route === "joint" && a.value > 0)) {
      add("info", "Joint ownership is shown here as part of the estate. For Muslim profiles, confirm with a Syariah adviser how joint ownership sits with faraid.");
    }
  }

  if (plan.people.some((p) => !p.name.trim())) add("info", "Some people have no name yet. Add names so the report reads clearly.");

  plan.will
    .filter((g) => byId.get(g.personId) && !byId.get(g.personId).alive)
    .forEach((g) => add("medium", `${byId.get(g.personId).name} is recorded as deceased but is still a ${doc} beneficiary. Their gift lapses.`));

  return flags.sort((a, b) => ORDER[a.level] - ORDER[b.level]);
}
