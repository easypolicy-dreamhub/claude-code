import { money } from "../format.js";
import { ROUTE_SUFFIX } from "../labels.js";
import { jointHolderCount, willIsDone } from "../model.js";
import { distributionAct } from "./intestacy.js";
import { faraid } from "./faraid.js";
import { classifyNominee, usesMalaysianNomineeRules } from "./insurance.js";
import { buildFlags } from "./flags.js";
import { administrationRoute } from "./route.js";

const LIQUID_CATEGORIES = ["cash", "investments", "insurance"];

// Works out who receives what if the person died today.
// useWill = false gives the "default law" comparison (as if there were no will).
export function computeOutcome(plan, useWill) {
  const notes = [];
  const byId = new Map(plan.people.map((p) => [p.id, p]));
  const credits = new Map();
  const credit = (personId, label, amount) => {
    if (!(amount > 0.005)) return;
    const list = credits.get(personId) ?? [];
    const row = list.find((r) => r.label === label);
    if (row) row.amount += amount;
    else list.push({ label, amount });
    credits.set(personId, list);
  };
  const livingPerson = (id) => {
    const p = byId.get(id);
    return p && p.alive ? p : undefined;
  };

  let totalAssets = 0;
  let estatePool = 0;
  let outsideEstate = 0;
  let unassigned = 0;
  let liquidCash = 0;
  let estateProperty = 0;
  const nonTrustNominees = [];
  const jointEstateShares = [];

  const addToEstate = (asset, amount) => {
    estatePool += amount;
    if (LIQUID_CATEGORIES.includes(asset.category)) liquidCash += amount;
    if (asset.category === "property") estateProperty += amount;
  };

  for (const asset of plan.assets) {
    const value = Math.max(0, Number(asset.value) || 0);
    const name = asset.name || "Unnamed asset";

    if (asset.route === "joint") {
      // Only the deceased's own share is theirs to leave (value / number of holders).
      const share = value / jointHolderCount(asset);
      totalAssets += share;
      if (asset.survivorship) {
        const survivor = asset.allocations[0] && livingPerson(asset.allocations[0].personId);
        if (survivor) {
          credit(survivor.id, `${name} (joint holder, survivorship)`, share);
          outsideEstate += share;
        } else {
          addToEstate(asset, share);
          notes.push(`"${name}" is marked as passing by survivorship but has no living surviving joint holder selected, so the deceased's share is treated as part of the estate.`);
        }
      } else {
        addToEstate(asset, share);
        jointEstateShares.push({ asset, share });
      }
      continue;
    }

    totalAssets += value;

    if (asset.route === "estate") {
      addToEstate(asset, value);
    } else if (asset.route === "nominee" && plan.muslim && plan.nomineeHeldForHeirs) {
      addToEstate(asset, value);
      notes.push(
        `"${name}" (${money(value)}) names a nominee, but is counted as part of the faraid estate here: the nominee is assumed to hold the proceeds on trust for the faraid heirs, not to keep them as a direct beneficiary gift. This matches the "Nominee held for heirs" setting — switch it off, or record a documented hibah instead, if the nominee is meant to keep the proceeds personally.`,
      );
    } else if (asset.route === "foundation") {
      notes.push(`"${name}" (${money(value)}) is held via a foundation or family office. It passes under that structure's own charter, so it is not included in the estate or outside-estate totals above.`);
    } else if (usesMalaysianNomineeRules(plan, asset)) {
      // Malaysian life insurance: FSA 2013 Schedule 10.
      const nominees = asset.allocations.filter((a) => a.pct > 0 && byId.has(a.personId));
      const living = nominees.filter((a) => livingPerson(a.personId));
      const livingTotal = living.reduce((sum, a) => sum + a.pct, 0);
      if (!living.length) {
        addToEstate(asset, value);
        notes.push(`"${name}" (${money(value)}) has no living nominee, so the nomination is ineffective and the proceeds are paid into the estate (FSA 2013 Schedule 10, para 6(3)).`);
      } else {
        const gone = nominees.filter((a) => !livingPerson(a.personId));
        if (gone.length) {
          notes.push(
            `"${name}": ${gone.map((a) => byId.get(a.personId).name.trim() || "a nominee").join(", ")} died before the policy owner, so the surviving nominee(s) share the whole sum between them (FSA 2013 Schedule 10, para 6(2)).`,
          );
        }
        for (const a of living) {
          const person = livingPerson(a.personId);
          const amount = (value * a.pct) / livingTotal;
          const verdict = classifyNominee(person, plan, asset);
          if (verdict.kind === "trust" || asset.assigned) {
            credit(person.id, `${name} (${verdict.kind === "trust" ? "trust nomination" : "assigned to nominee"})`, amount);
            outsideEstate += amount;
          } else {
            addToEstate(asset, amount);
            nonTrustNominees.push({ asset, person, amount, reason: verdict.reason });
            notes.push(
              `"${name}": ${person.name.trim() || "the nominee"}, who ${verdict.reason}, receives ${money(amount)} as executor for the estate, not personally (FSA 2013 Schedule 10, para 6). It is added to the estate and shared under the will or the Distribution Act.`,
            );
          }
        }
      }
    } else {
      // Other nominations (EPF, CPF, foreign policies), trusts and hibah follow the percentages entered.
      const living = asset.allocations.filter((a) => a.pct > 0 && livingPerson(a.personId));
      const pctTotal = living.reduce((sum, a) => sum + a.pct, 0);
      const scale = pctTotal > 100 ? 100 / pctTotal : 1;
      let given = 0;
      living.forEach((a) => {
        const amount = value * ((a.pct * scale) / 100);
        credit(a.personId, `${name} (${ROUTE_SUFFIX[asset.route] ?? asset.route})`, amount);
        given += amount;
      });
      outsideEstate += given;
      addToEstate(asset, value - given);
      if (value - given > 0.5) {
        notes.push(`Part of "${name}" (${money(value - given)}) has no living beneficiary, so it falls back into the estate.`);
      }
    }
  }

  const netEstate = Math.max(0, estatePool - Math.max(0, plan.liabilities || 0));
  if (plan.liabilities > estatePool && estatePool > 0) {
    notes.push("Liabilities are larger than the estate assets, so nothing is left to distribute.");
  }

  const law = plan.muslim ? faraid(plan.people, plan.clientGender) : distributionAct(plan.people);
  notes.push(...law.notes);

  let remaining = netEstate;
  const giftLabel = plan.muslim ? "Wasiat" : "Will";

  if (useWill && plan.will.length && netEstate > 0) {
    const gifts = plan.will.filter((g) => g.pct > 0 && livingPerson(g.personId));
    if (plan.muslim) {
      const heirs = new Set(law.shares.map((s) => s.personId));
      const allowed = gifts.filter((g) => {
        if (!heirs.has(g.personId)) return true;
        notes.push(`Wasiat to ${byId.get(g.personId)?.name} is ignored: an heir cannot take under a wasiat unless the other heirs agree.`);
        return false;
      });
      const total = allowed.reduce((sum, g) => sum + g.pct, 0);
      const cap = 100 / 3;
      const factor = total > cap ? cap / total : 1;
      if (factor < 1) notes.push(`Wasiat totals ${total.toFixed(1)}% of the net estate. It is capped at one-third (33.3%) without heirs' consent.`);
      allowed.forEach((g) => {
        const amount = netEstate * ((g.pct * factor) / 100);
        credit(g.personId, giftLabel, amount);
        remaining -= amount;
      });
    } else {
      // A will gift to someone who (or whose spouse) witnessed the will is void under the Wills Act 1959.
      const voided = new Set(plan.voidWillIds ?? []);
      const valid = gifts.filter((g) => {
        if (!voided.has(g.personId)) return true;
        notes.push(`The gift to ${byId.get(g.personId)?.name || "a witness"} is void: they (or their spouse) witnessed the will, which cancels the gift under the Wills Act 1959. That share falls into intestacy.`);
        return false;
      });
      const total = valid.reduce((sum, g) => sum + g.pct, 0);
      const factor = total > 100 ? 100 / total : 1;
      valid.forEach((g) => {
        const amount = netEstate * ((g.pct * factor) / 100);
        credit(g.personId, giftLabel, amount);
        remaining -= amount;
      });
      if (total < 99.99) notes.push(`Will gifts total ${total.toFixed(1)}%. The rest of the estate is treated as intestate.`);
    }
  }

  if (remaining > 0.005) {
    const label = plan.muslim ? "Faraid" : "Distribution Act";
    if (law.shares.length) law.shares.forEach((s) => credit(s.personId, `${label} (estate)`, remaining * s.fraction));
    else unassigned += remaining;
  }

  const ids = new Set(plan.people.filter((p) => p.alive).map((p) => p.id));
  credits.forEach((_, id) => ids.add(id));
  const people = [...ids]
    .map((id) => byId.get(id))
    .filter(Boolean)
    .map((person) => {
      const contributions = credits.get(person.id) ?? [];
      return { person, contributions, total: contributions.reduce((sum, c) => sum + c.amount, 0) };
    })
    .sort((a, b) => b.total - a.total);

  return {
    people,
    totalAssets,
    estatePool,
    netEstate,
    outsideEstate,
    unassigned,
    liquidCash,
    estateProperty,
    nonTrustNominees,
    jointEstateShares,
    law: law.law,
    lawShares: law.shares,
    notes: [...new Set(notes)],
  };
}

export function analyse(plan) {
  const willInPlace = willIsDone(plan);
  const current = computeOutcome(plan, willInPlace);
  const defaultLaw = computeOutcome(plan, false);
  return {
    willInPlace,
    current,
    defaultLaw,
    route: administrationRoute(plan, current, willInPlace),
    flags: buildFlags(plan, current, defaultLaw, willInPlace),
  };
}
