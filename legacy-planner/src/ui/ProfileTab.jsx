import { useState } from "react";
import { REL_LABEL, RELATIONSHIPS, STATE_LABEL, personName } from "../labels.js";
import { uid } from "../format.js";
import { Field, Group, NumInput, Seg } from "./controls.jsx";

const newPerson = (relationship) => ({ id: uid(), name: "", relationship, alive: true, minor: false });

export function ProfileTab({ plan, update }) {
  const [toAdd, setToAdd] = useState("spouse");
  const children = plan.people.filter((p) => p.relationship === "son" || p.relationship === "daughter");
  const patchPerson = (id, change) => update((p) => ({ ...p, people: p.people.map((x) => (x.id === id ? { ...x, ...change } : x)) }));
  const removePerson = (id) =>
    update((p) => ({
      ...p,
      people: p.people.filter((x) => x.id !== id),
      will: p.will.filter((g) => g.personId !== id),
      voidWillIds: (p.voidWillIds ?? []).filter((x) => x !== id),
      assets: p.assets.map((a) => ({ ...a, allocations: a.allocations.filter((x) => x.personId !== id) })),
    }));

  return (
    <div className="stack">
      <section className="card">
        <h2>Profile</h2>
        <p className="sub">Religion decides which law applies when there is no will.</p>
        <div className="grid mt">
          <Field label="Profile name">
            <input type="text" value={plan.clientName} onChange={(e) => update((p) => ({ ...p, clientName: e.target.value }))} />
          </Field>
          <Group label="Their gender (sets the spouse's share under faraid)">
            <Seg
              value={plan.clientGender}
              options={[
                { value: "male", label: "Male" },
                { value: "female", label: "Female" },
              ]}
              onChange={(v) => update((p) => ({ ...p, clientGender: v }))}
            />
          </Group>
          <Field label="Debts and funeral costs (paid from the estate)">
            <NumInput value={plan.liabilities} onChange={(v) => update((p) => ({ ...p, liabilities: v }))} step={1000} ariaLabel="Liabilities" />
          </Field>
          <Field label="Estimated executor, legal and probate costs (optional)">
            <NumInput value={plan.adminCostEstimate ?? 0} onChange={(v) => update((p) => ({ ...p, adminCostEstimate: v }))} step={1000} ariaLabel="Administration costs" />
          </Field>
          {!plan.muslim && (
            <Field label="State where they live">
              <select value={plan.state ?? "peninsular"} onChange={(e) => update((p) => ({ ...p, state: e.target.value }))}>
                {Object.entries(STATE_LABEL).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </Field>
          )}
          {plan.people.some((p) => p.relationship === "spouse" && p.alive) && (
            <Field label="Date of current marriage (optional)">
              <input type="date" value={plan.marriedOn ?? ""} onChange={(e) => update((p) => ({ ...p, marriedOn: e.target.value || undefined }))} />
            </Field>
          )}
        </div>

        {plan.marriedOn && (
          <>
            <p className="hint mt">
              Used to check whether the {plan.muslim ? "" : "will and "}any Singapore CPF nomination pre-date the marriage — marriage automatically revokes an earlier will (unless made in contemplation of it) and an earlier CPF nomination in Singapore, if they have CPF savings marked "Held outside Malaysia" in the Assets tab.
            </p>
            {!plan.muslim && (
              <label className="check">
                <input
                  type="checkbox"
                  checked={plan.willMadeInContemplationOfMarriage}
                  onChange={(e) => update((p) => ({ ...p, willMadeInContemplationOfMarriage: e.target.checked }))}
                />
                The will states it was made in contemplation of this marriage (exempts it from automatic revocation)
              </label>
            )}
          </>
        )}

        <div className="mt">
          <label className="check">
            <input type="checkbox" checked={plan.muslim} onChange={(e) => update((p) => ({ ...p, muslim: e.target.checked }))} />
            Muslim (faraid applies to the estate)
          </label>
          {plan.muslim && (
            <label className="check">
              <input type="checkbox" checked={plan.nomineeHeldForHeirs} onChange={(e) => update((p) => ({ ...p, nomineeHeldForHeirs: e.target.checked }))} />
              Treat nominees (takaful, EPF, CPF) as holding for the faraid heirs
            </label>
          )}
        </div>

        <div className="mt">
          <Field label="Adviser notes (kept with this profile, included in the report)">
            <textarea value={plan.advisorNotes} onChange={(e) => update((p) => ({ ...p, advisorNotes: e.target.value }))} />
          </Field>
        </div>
        <p className="sub mt">
          Rules applied for Malaysia:{" "}
          {plan.muslim
            ? "faraid for the estate, with a wasiat limited to one-third for non-heirs."
            : "Distribution Act 1958 for the estate when there is no will (Peninsular Malaysia and Sarawak)."}
        </p>
      </section>

      <section className="card">
        <div className="row spread">
          <div>
            <h2>Family and beneficiaries</h2>
            <p className="sub">Everyone who could inherit, plus friends, charities or trusts named in the plan.</p>
          </div>
        </div>
        <p className="hint">
          If they have since divorced, mark the former spouse as "Ex-spouse" here rather than removing them — divorce does not automatically remove a former spouse from a will, CPF/EPF nomination or insurance nomination in Malaysia or Singapore, so the app can check whether they are still named anywhere.
        </p>
        <div className="stack mt">
          {plan.people.length === 0 && <p className="hint">No one added yet. Start with the spouse and children.</p>}
          {plan.people.map((person) => (
            <div className="person" key={person.id}>
              <div className="person-grid">
                <Field label="Name">
                  <input type="text" value={person.name} placeholder={REL_LABEL[person.relationship]} onChange={(e) => patchPerson(person.id, { name: e.target.value })} />
                </Field>
                <Field label="Relationship">
                  <select value={person.relationship} onChange={(e) => patchPerson(person.id, { relationship: e.target.value })}>
                    {RELATIONSHIPS.map((r) => (
                      <option key={r} value={r}>
                        {REL_LABEL[r]}
                      </option>
                    ))}
                  </select>
                </Field>
                {person.relationship === "grandchild" && (
                  <Field label="Child of">
                    <select value={person.parentId ?? ""} onChange={(e) => patchPerson(person.id, { parentId: e.target.value || undefined })}>
                      <option value="">Choose parent</option>
                      {children.map((c) => (
                        <option key={c.id} value={c.id}>
                          {personName(c)}
                        </option>
                      ))}
                    </select>
                  </Field>
                )}
              </div>
              <div className="row spread">
                <div className="row">
                  <label className="check">
                    <input type="checkbox" checked={person.alive} onChange={(e) => patchPerson(person.id, { alive: e.target.checked })} />
                    Living
                  </label>
                  <label className="check">
                    <input type="checkbox" checked={person.minor} onChange={(e) => patchPerson(person.id, { minor: e.target.checked })} />
                    Minor / cannot receive directly
                  </label>
                </div>
                <button type="button" className="btn ghost small danger" onClick={() => removePerson(person.id)}>
                  Remove
                </button>
              </div>
            </div>
          ))}
        </div>
        <div className="row mt">
          <select style={{ maxWidth: 260 }} aria-label="Relationship to add" value={toAdd} onChange={(e) => setToAdd(e.target.value)}>
            {RELATIONSHIPS.map((r) => (
              <option key={r} value={r}>
                {REL_LABEL[r]}
              </option>
            ))}
          </select>
          <button type="button" className="btn primary" onClick={() => update((p) => ({ ...p, people: [...p.people, newPerson(toAdd)] }))}>
            + Add person
          </button>
        </div>
      </section>
    </div>
  );
}
