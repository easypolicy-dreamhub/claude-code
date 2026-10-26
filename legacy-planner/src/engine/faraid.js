import { fraction } from "../format.js";

// Faraid (Muslim inheritance law). Simplified model, unchanged from the first version of the app.
export function faraid(people, gender) {
  const law = "Faraid (Muslim inheritance law)";
  const notes = [];
  const alive = people.filter((p) => p.alive);
  const spouses = alive.filter((p) => p.relationship === "spouse");
  const sons = alive.filter((p) => p.relationship === "son");
  const daughters = alive.filter((p) => p.relationship === "daughter");
  const father = alive.find((p) => p.relationship === "father");
  const mother = alive.find((p) => p.relationship === "mother");
  const brothers = alive.filter((p) => p.relationship === "brother");
  const sisters = alive.filter((p) => p.relationship === "sister");
  const hasChildren = sons.length + daughters.length > 0;
  const siblingCount = brothers.length + sisters.length;

  if (gender === "female" && spouses.length > 1) {
    notes.push("A woman can only have one husband at a time; the spouse share is split equally, so please review.");
  }
  if (gender === "male" && spouses.length > 4) notes.push("More than four wives are recorded; please review.");
  if (alive.some((p) => p.relationship === "grandchild")) {
    notes.push("Grandchildren are not modelled under faraid. They may inherit in some situations (for example through a predeceased son).");
  }

  const shares = new Map();
  const add = (id, fractionValue, part) => {
    const entry = shares.get(id) ?? { fraction: 0, parts: [] };
    entry.fraction += fractionValue;
    entry.parts.push(part);
    shares.set(id, entry);
  };

  let spouseShare = 0;
  if (spouses.length) {
    spouseShare = gender === "male" ? (hasChildren ? 1 / 8 : 1 / 4) : hasChildren ? 1 / 4 : 1 / 2;
    const label = fraction(spouseShare) + (spouses.length > 1 ? ` shared by ${spouses.length} wives` : "");
    spouses.forEach((s) => add(s.id, spouseShare / spouses.length, `${label} (${hasChildren ? "children present" : "no children"})`));
  }

  if (mother) {
    if (hasChildren || siblingCount >= 2) add(mother.id, 1 / 6, "1/6 (children or 2+ siblings present)");
    else if (spouses.length && father) add(mother.id, (1 - spouseShare) / 3, "1/3 of the remainder after the spouse (Umariyyatain)");
    else add(mother.id, 1 / 3, "1/3");
  }
  if (father && hasChildren) add(father.id, 1 / 6, "1/6 (children present)");

  if (daughters.length && !sons.length) {
    const share = daughters.length === 1 ? 0.5 : 2 / 3;
    daughters.forEach((d) =>
      add(d.id, share / daughters.length, `${fraction(share)} for ${daughters.length === 1 ? "a sole daughter" : "daughters, equally"}`),
    );
  }

  const noSonOrFather = !sons.length && !father;
  if (noSonOrFather && !hasChildren && sisters.length && !brothers.length) {
    const share = sisters.length === 1 ? 0.5 : 2 / 3;
    sisters.forEach((s) => add(s.id, share / sisters.length, `${fraction(share)} for ${sisters.length === 1 ? "a sole sister" : "sisters, equally"}`));
  }

  const fixedTotal = [...shares.values()].reduce((sum, e) => sum + e.fraction, 0);
  if (fixedTotal > 1 + 1e-9) {
    shares.forEach((e) => {
      e.fraction /= fixedTotal;
      e.parts.push("reduced proportionally (awl)");
    });
    notes.push("The fixed shares add up to more than the whole estate, so every share is reduced proportionally (awl).");
  } else {
    const residue = 1 - fixedTotal;
    if (residue > 1e-9) {
      if (sons.length) {
        const units = sons.length * 2 + daughters.length;
        sons.forEach((s) => add(s.id, (residue * 2) / units, "residue, son gets twice a daughter"));
        daughters.forEach((d) => add(d.id, residue / units, "residue, daughter gets half a son"));
      } else if (father) {
        add(father.id, residue, "residue (asabah)");
      } else if (noSonOrFather && (brothers.length || (daughters.length && sisters.length))) {
        const units = brothers.length * 2 + sisters.length;
        brothers.forEach((b) => add(b.id, (residue * 2) / units, "residue, brother gets twice a sister"));
        sisters.forEach((s) => add(s.id, residue / units, "residue, sister gets half a brother"));
      } else {
        const nonSpouse = [...shares.entries()].filter(([id]) => !spouses.some((s) => s.id === id));
        const receivers = nonSpouse.length ? nonSpouse : [...shares.entries()];
        if (receivers.length) {
          const receiverTotal = receivers.reduce((sum, [, e]) => sum + e.fraction, 0);
          receivers.forEach(([id, e]) => {
            e.fraction += (residue * e.fraction) / receiverTotal;
            e.parts.push("plus share of returned surplus (radd)");
            shares.set(id, e);
          });
          notes.push(
            nonSpouse.length
              ? "The fixed shares leave a surplus with no residuary heir, so it is returned to the fixed-share heirs other than the spouse (radd)."
              : "Only the spouse is a fixed-share heir, so the surplus is shown as returned to them (radd). Some state fatwas pay it to Baitulmal instead.",
          );
        } else {
          notes.push("No faraid heirs are recorded. The estate would go to Baitulmal under state rules.");
        }
      }
    }
  }

  const result = [...shares.entries()].map(([personId, e]) => ({ personId, fraction: e.fraction, basis: e.parts.join(" + ") }));
  return { law, shares: result.filter((s) => s.fraction > 1e-12), notes };
}
