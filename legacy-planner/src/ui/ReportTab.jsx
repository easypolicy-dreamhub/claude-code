import { money } from "../format.js";
import { CATEGORY_LABEL, ROUTE_LABEL, STATUS_LABEL, personName } from "../labels.js";
import { GROUPS, checklistProgress } from "../model.js";
import { analyse } from "../engine/outcome.js";
import { Bars, CompareTable, DefaultLaw, Flags, RouteBox, Tiles } from "./OutcomeParts.jsx";

export const DISCLAIMER =
  "This report is a simplified, illustrative estimate to support a planning discussion. It is not legal, tax, Syariah or financial advice. Laws, fatwas and practice change and depend on facts this tool does not fully capture (for example adopted children, remote relatives, the fine detail of cross-border probate for foreign assets, and family provision claims). Confirm with a qualified lawyer or Syariah adviser before acting.";

export function ReportTab({ plan }) {
  const outcome = analyse(plan);
  const { items, done, total } = checklistProgress(plan);
  const people = new Map(plan.people.map((p) => [p.id, p]));
  const today = new Date().toLocaleDateString("en-GB", { day: "numeric", month: "long", year: "numeric" });

  return (
    <div className="stack report">
      <div className="row spread no-print">
        <p className="sub">A polished summary, ready to share. Use Print, then choose Save as PDF.</p>
        <button type="button" className="btn primary" onClick={() => window.print()}>
          Print / save as PDF
        </button>
      </div>
      <section className="card">
        <h1>Estate and legacy plan summary</h1>
        <p className="sub">
          {plan.clientName || "Profile"} · Malaysia · {plan.muslim ? "Muslim (faraid)" : "Non-Muslim"} · Prepared {today}
        </p>
        {plan.advisorNotes.trim() && (
          <>
            <h3>Adviser notes</h3>
            <p style={{ whiteSpace: "pre-wrap" }}>{plan.advisorNotes}</p>
          </>
        )}
        <h3>Position at a glance</h3>
        <Tiles plan={plan} outcome={outcome} />
        <RouteBox route={outcome.route} />

        <h3>
          What is in place ({done} of {total} done)
        </h3>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Status</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              {GROUPS.flatMap((group) =>
                items
                  .filter((i) => i.group === group)
                  .map((i) => {
                    const state = plan.checklist[i.id];
                    return (
                      <tr key={i.id}>
                        <td>{i.title}</td>
                        <td>{STATUS_LABEL[state?.status ?? "todo"]}</td>
                        <td>
                          {state?.notes}
                          {state?.date ? ` (${state.date})` : ""}
                        </td>
                      </tr>
                    );
                  }),
              )}
            </tbody>
          </table>
        </div>

        <h3>Assets and how they pass</h3>
        {plan.assets.length === 0 ? (
          <p className="hint">No assets recorded.</p>
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Asset</th>
                  <th>Type</th>
                  <th>Passes by</th>
                  <th className="num">Value</th>
                </tr>
              </thead>
              <tbody>
                {plan.assets.map((asset) => {
                  const who = asset.allocations
                    .map((a) => {
                      const person = people.get(a.personId);
                      return person ? `${personName(person)}${asset.route === "joint" ? "" : ` ${a.pct}%`}` : "";
                    })
                    .filter(Boolean)
                    .join(", ");
                  const jointNote = asset.route === "joint" ? ` (${asset.jointHolders ?? 2} holders${asset.survivorship ? ", survivorship" : ""})` : "";
                  return (
                    <tr key={asset.id}>
                      <td>{asset.name || "Unnamed asset"}</td>
                      <td>{CATEGORY_LABEL[asset.category]}</td>
                      <td>
                        {ROUTE_LABEL[asset.route]}
                        {jointNote}
                        {who ? `: ${who}` : ""}
                        {asset.foreign ? " (held outside Malaysia)" : ""}
                      </td>
                      <td className="num">{money(asset.value)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        <h3>Who receives what</h3>
        <Bars plan={plan} outcome={outcome} open />
        <div className="mt">
          <CompareTable outcome={outcome} />
        </div>
        <h3>Gaps and things to check</h3>
        <Flags flags={outcome.flags} />
        <h3>The default law</h3>
        <DefaultLaw plan={plan} outcome={outcome} />
        <p className="disclaimer">{DISCLAIMER}</p>
      </section>
    </div>
  );
}
