import { uid, money } from "../format.js";
import { BUSINESS_TYPE_HINT, BUSINESS_TYPE_LABEL, CATEGORY_LABEL, NOMINATION_LABEL, ROUTE_LABEL, personLabel } from "../labels.js";
import { deceasedValue, jointHolderCount, willIsDone } from "../model.js";
import { classifyNominee, usesMalaysianNomineeRules } from "../engine/insurance.js";
import { Allocator, Field, MoneyInput } from "./controls.jsx";

const ROUTE_HINT = {
  estate: "Follows the will or wasiat. Without one, it follows the default law.",
  nominee: "Paid to the named nominees, outside the will (EPF, CPF, takaful and insurance). For Malaysian life insurance, only a spouse, child or parent nominee keeps the money — anyone else holds it for the estate.",
  joint: "The deceased's share (value divided by the number of holders) generally falls into the estate. Enter the full value of the asset; the app takes the deceased's share.",
  trust: "Distributed by the trust deed. Enter the trust beneficiaries.",
  hibah: "A completed lifetime gift that no longer belongs to the estate.",
  foundation: "Governed by the foundation's or family office's own charter — not the will and not modelled in the outcome below.",
};

function foreignHint(category) {
  const lead =
    category === "retirement"
      ? "Treated as Singapore CPF savings. CPF nomination rules apply regardless of the member's nationality or domicile — marriage automatically revokes an existing nomination (divorce does not), unlike EPF. A separate grant of representation (or resealing of the Malaysian grant) is usually needed in Singapore to release it."
      : category === "insurance"
        ? "Singapore's Insurance Act governs this nomination, not Malaysia's. Set the nomination type below — a trust nomination is only valid for a spouse or children."
        : category === "property"
          ? "Property located in Singapore is normally governed by Singapore's own succession law (not Malaysia's Distribution Act or faraid), regardless of the Malaysian will — confirm with a Singapore-qualified lawyer."
          : "A Malaysian grant of probate generally does not, by itself, let the executor deal with an asset outside Malaysia — a separate grant (or resealing) is usually needed there.";
  return `${lead} Enter the value in Ringgit (convert at today's rate if it's held in another currency) so totals stay comparable.`;
}

export function AssetsTab({ plan, update }) {
  const patch = (id, change) => update((p) => ({ ...p, assets: p.assets.map((a) => (a.id === id ? { ...a, ...change } : a)) }));
  const total = plan.assets.reduce((sum, a) => sum + deceasedValue(a), 0);
  const willDone = willIsDone(plan);
  const giftName = plan.muslim ? "Wasiat" : "Will";

  return (
    <div className="stack">
      <section className="card">
        <div className="row spread">
          <div>
            <h2>Assets and how they pass</h2>
            <p className="sub">For each asset, record how it passes on death. That decides whether the will or the default law controls it.</p>
          </div>
          <div className="tile" style={{ minWidth: 150 }}>
            <div className="label">Total assets</div>
            <div className="value">{money(total)}</div>
            <div className="note">the deceased's own share</div>
          </div>
        </div>
      </section>

      {plan.assets.length === 0 && <p className="hint">No assets yet. Add the main ones: property, savings, investments, insurance, CPF/EPF and any business.</p>}

      {plan.assets.map((asset) => {
        const heldForHeirs = asset.route === "nominee" && plan.muslim && plan.nomineeHeldForHeirs;
        const malaysianPolicy = usesMalaysianNomineeRules(plan, asset);
        return (
          <section className="card" key={asset.id} style={{ display: "grid", gap: 12 }}>
            <div className="grid">
              <Field label="Asset">
                <input type="text" value={asset.name} placeholder="e.g. Family home" onChange={(e) => patch(asset.id, { name: e.target.value })} />
              </Field>
              <Field label="Type">
                <select value={asset.category} onChange={(e) => patch(asset.id, { category: e.target.value })}>
                  {Object.keys(CATEGORY_LABEL).map((k) => (
                    <option key={k} value={k}>
                      {CATEGORY_LABEL[k]}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Value (RM)">
                <MoneyInput value={asset.value} onChange={(v) => patch(asset.id, { value: v })} ariaLabel="Value" />
              </Field>
              <Field label="How it passes on death">
                <select value={asset.route} onChange={(e) => patch(asset.id, { route: e.target.value, allocations: [] })}>
                  {Object.keys(ROUTE_LABEL).map((k) => (
                    <option key={k} value={k}>
                      {ROUTE_LABEL[k]}
                    </option>
                  ))}
                </select>
              </Field>
            </div>
            <p className="hint">{ROUTE_HINT[asset.route]}</p>

            <label className="check">
              <input type="checkbox" checked={!!asset.foreign} onChange={(e) => patch(asset.id, { foreign: e.target.checked })} />
              Held outside Malaysia (e.g. Singapore — CPF, a bank account, insurance or property from working there)
            </label>
            {asset.foreign && <p className="hint">{foreignHint(asset.category)}</p>}

            {asset.category === "business" && (
              <>
                <Field label="Business type">
                  <select value={asset.businessType ?? ""} onChange={(e) => patch(asset.id, { businessType: e.target.value || undefined })}>
                    <option value="">Not set</option>
                    {Object.keys(BUSINESS_TYPE_LABEL).map((k) => (
                      <option key={k} value={k}>
                        {BUSINESS_TYPE_LABEL[k]}
                      </option>
                    ))}
                  </select>
                </Field>
                {asset.businessType && <p className="hint">{BUSINESS_TYPE_HINT[asset.businessType]}</p>}
              </>
            )}

            {asset.category === "insurance" && asset.route === "nominee" && asset.foreign && !heldForHeirs && (
              <Field label="Nomination type (Singapore Insurance Act)">
                <select value={asset.nominationType ?? ""} onChange={(e) => patch(asset.id, { nominationType: e.target.value || undefined })}>
                  <option value="">Not set — check the policy</option>
                  {Object.keys(NOMINATION_LABEL).map((k) => (
                    <option key={k} value={k}>
                      {NOMINATION_LABEL[k]}
                    </option>
                  ))}
                </select>
              </Field>
            )}

            {asset.route === "joint" && (
              <>
                <div className="grid">
                  <Field label="Number of joint holders (including the deceased)">
                    <input
                      type="number"
                      inputMode="numeric"
                      min={1}
                      step={1}
                      value={jointHolderCount(asset)}
                      onChange={(e) => patch(asset.id, { jointHolders: Math.max(1, Math.round(Number(e.target.value) || 1)) })}
                    />
                  </Field>
                </div>
                <p className="hint">
                  The deceased's share is {money(deceasedValue(asset))} ({jointHolderCount(asset)} equal holders). This is counted as part of the estate.
                </p>
                <label className="check">
                  <input type="checkbox" checked={!!asset.survivorship} onChange={(e) => patch(asset.id, { survivorship: e.target.checked })} />
                  The title or account mandate expressly gives survivorship (the deceased's share then passes to the surviving holder, outside the estate)
                </label>
                {asset.survivorship && (
                  <Field label="Surviving joint holder">
                    <select
                      value={asset.allocations[0]?.personId ?? ""}
                      onChange={(e) => patch(asset.id, { allocations: e.target.value ? [{ personId: e.target.value, pct: 100 }] : [] })}
                    >
                      <option value="">Choose person</option>
                      {plan.people.map((p) => (
                        <option key={p.id} value={p.id}>
                          {personLabel(p)}
                        </option>
                      ))}
                    </select>
                  </Field>
                )}
              </>
            )}

            {(asset.route === "trust" || asset.route === "hibah" || (asset.route === "nominee" && !heldForHeirs)) && (
              <Allocator people={plan.people} value={asset.allocations} onChange={(v) => patch(asset.id, { allocations: v })} />
            )}

            {malaysianPolicy && (
              <>
                <div className="grid">
                  <Field label="Date the current nomination was made">
                    <input type="date" value={asset.nominatedOn ?? ""} onChange={(e) => patch(asset.id, { nominatedOn: e.target.value || undefined })} />
                  </Field>
                </div>
                <p className="hint">
                  Trust status is fixed on the day of nomination. A parent nominated before the marriage stays a trust nominee; a parent nominated after it, or a fiancé(e) nominated before it, holds the money for the estate.
                  {!plan.marriedOn && " Add the marriage date in the Profile tab so the app can check this."}
                </p>
                {asset.allocations
                  .filter((a) => a.pct > 0)
                  .map((a) => plan.people.find((p) => p.id === a.personId))
                  .filter(Boolean)
                  .map((person) => {
                    const verdict = classifyNominee(person, plan, asset);
                    return (
                      <p className="hint" key={person.id}>
                        <b>{person.name.trim() || "Unnamed"}:</b>{" "}
                        {verdict.kind === "trust"
                          ? `holds as beneficiary on an automatic trust (${verdict.reason}). Paid directly, outside the estate and the will, and safe from creditors.`
                          : asset.assigned
                            ? "assigned in writing, so keeps the money personally."
                            : `${verdict.reason}, so receives the money as executor for the estate, not personally.`}
                      </p>
                    );
                  })}
                <label className="check">
                  <input type="checkbox" checked={!!asset.assigned} onChange={(e) => patch(asset.id, { assigned: e.target.checked })} />
                  The policy benefits have been assigned in writing to the nominee(s), so they keep the money personally
                </label>
              </>
            )}

            {heldForHeirs && (
              <p className="hint">
                Treated as held for the faraid heirs, so it is added to the estate and shared under faraid. Change this in the Profile tab, or use the hibah route if a lifetime gift is documented.
              </p>
            )}
            {asset.route === "foundation" && (
              <p className="hint">
                Value is excluded from both the estate and the outside-estate totals in the outcome, since it passes under the structure's own charter rather than the will or default law. Use the checklist to track that this structure has been reviewed.
              </p>
            )}

            <div className="row spread">
              <span />
              <button type="button" className="btn ghost small danger" onClick={() => update((p) => ({ ...p, assets: p.assets.filter((x) => x.id !== asset.id) }))}>
                Remove asset
              </button>
            </div>
          </section>
        );
      })}

      <div>
        <button
          type="button"
          className="btn primary"
          onClick={() => update((p) => ({ ...p, assets: [...p.assets, { id: uid(), name: "", category: "cash", value: 0, route: "estate", allocations: [] }] }))}
        >
          + Add asset
        </button>
      </div>

      <section className="card">
        <h2>{giftName} beneficiaries</h2>
        <p className="sub">
          {plan.muslim
            ? "Wasiat gifts as a percentage of the net estate. Only non-heirs can receive, and the total is capped at one-third."
            : "Who receives the estate under the will, as a percentage of the estate. Anything not allocated follows the default law."}
        </p>
        {!willDone && (
          <p className="hint mt">The {giftName.toLowerCase()} is not marked Done in the checklist, so these gifts are ignored in the outcome. Mark it Done once it is signed.</p>
        )}
        <div className="mt">
          <Allocator people={plan.people} value={plan.will} onChange={(v) => update((p) => ({ ...p, will: v }))} />
        </div>
      </section>
    </div>
  );
}
