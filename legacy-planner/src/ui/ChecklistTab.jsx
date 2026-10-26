import { GROUPS, checklistProgress } from "../model.js";
import { STATUS_LABEL, personName } from "../labels.js";
import { Field, Seg } from "./controls.jsx";

const STATUS_OPTIONS = ["done", "in_progress", "todo", "na"].map((value) => ({ value, label: STATUS_LABEL[value] }));
const YES_NO = [
  { value: "yes", label: "Yes" },
  { value: "no", label: "No" },
];

// Extra checks that apply once a (non-Muslim) will is marked done: Wills Act 1959 validity.
function WillChecks({ plan, update }) {
  const checks = plan.willChecks ?? {};
  const setCheck = (key, value) => update((p) => ({ ...p, willChecks: { ...p.willChecks, [key]: p.willChecks?.[key] === value ? undefined : value } }));
  const beneficiaries = plan.will.filter((g) => g.pct > 0).map((g) => plan.people.find((p) => p.id === g.personId)).filter(Boolean);
  const voided = new Set(plan.voidWillIds ?? []);
  const toggleVoid = (id, on) => update((p) => ({ ...p, voidWillIds: on ? [...new Set([...(p.voidWillIds ?? []), id])] : (p.voidWillIds ?? []).filter((x) => x !== id) }));
  return (
    <div className="stack" style={{ borderTop: "1px solid var(--line)", paddingTop: 12 }}>
      <b>Will validity (Wills Act 1959)</b>
      <div className="grid">
        <div className="field" role="group" aria-label="Signed before two witnesses">
          <span>Signed in front of two witnesses present together?</span>
          <Seg value={checks.twoWitnesses} options={YES_NO} onChange={(v) => setCheck("twoWitnesses", v)} />
        </div>
        <div className="field" role="group" aria-label="Age at signing">
          <span>Writer was {plan.state === "sabah" ? "21" : "18"} or over when signing?</span>
          <Seg value={checks.ageOk} options={YES_NO} onChange={(v) => setCheck("ageOk", v)} />
        </div>
      </div>
      <div className="field">
        <span>Tick any beneficiary who — or whose spouse — witnessed the will (their gift is void):</span>
        {beneficiaries.length === 0 ? (
          <p className="hint">Add the will beneficiaries in the Assets tab first.</p>
        ) : (
          beneficiaries.map((p) => (
            <label className="check" key={p.id}>
              <input type="checkbox" checked={voided.has(p.id)} onChange={(e) => toggleVoid(p.id, e.target.checked)} />
              {personName(p)}
            </label>
          ))
        )}
      </div>
    </div>
  );
}

export function ChecklistTab({ plan, update }) {
  const { total, done, items } = checklistProgress(plan);
  const setItem = (id, change) =>
    update((p) => ({ ...p, checklist: { ...p.checklist, [id]: { ...(p.checklist[id] ?? { status: "todo", notes: "" }), ...change } } }));

  return (
    <div className="stack">
      <section className="card">
        <div className="row spread">
          <div>
            <h2>What's in place</h2>
            <p className="sub">Mark each item. Only a will or wasiat marked Done is used in the outcome.</p>
          </div>
          <b>
            {done} of {total} done
          </b>
        </div>
        <div className="progress mt" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={done}>
          <span style={{ width: total ? `${(done / total) * 100}%` : "0%" }} />
        </div>
      </section>

      {GROUPS.map((group) => {
        const groupItems = items.filter((i) => i.group === group);
        if (!groupItems.length) return null;
        return (
          <div className="stack" key={group}>
            <div className="group-title">{group}</div>
            {groupItems.map((item) => {
              const state = plan.checklist[item.id] ?? { status: "todo", notes: "" };
              return (
                <section className="card item" key={item.id}>
                  <div className="head">
                    <div className="row" style={{ flexWrap: "nowrap", alignItems: "flex-start" }}>
                      <span className={`status-dot ${state.status}`} aria-hidden />
                      <div>
                        <h3>{item.title}</h3>
                        <p className="sub">{item.why}</p>
                      </div>
                    </div>
                  </div>
                  <Seg value={state.status} options={STATUS_OPTIONS} onChange={(v) => setItem(item.id, { status: v })} />
                  {state.status !== "na" && (
                    <div className="item-fields">
                      <Field label="Notes">
                        <input
                          type="text"
                          value={state.notes}
                          placeholder="Who holds it, where it is kept, what is missing"
                          onChange={(e) => setItem(item.id, { notes: e.target.value })}
                        />
                      </Field>
                      <Field label="Signed / reviewed">
                        <input type="date" value={state.date ?? ""} onChange={(e) => setItem(item.id, { date: e.target.value || undefined })} />
                      </Field>
                    </div>
                  )}
                  {item.id === "will" && !plan.muslim && state.status === "done" && <WillChecks plan={plan} update={update} />}
                </section>
              );
            })}
          </div>
        );
      })}
    </div>
  );
}
