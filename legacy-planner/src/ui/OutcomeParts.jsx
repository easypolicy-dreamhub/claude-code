import { fraction, money, percent } from "../format.js";
import { REL_SHORT, personName } from "../labels.js";

const FLAG_TAG = { high: "▲ Action", medium: "◆ Check", info: "ℹ Note" };

export function Tiles({ plan, outcome }) {
  const o = outcome.current;
  return (
    <div className="tiles">
      <div className="tile">
        <div className="label">Total assets</div>
        <div className="value">{money(o.totalAssets)}</div>
      </div>
      <div className="tile">
        <div className="label">Passes through the estate</div>
        <div className="value">{money(o.netEstate)}</div>
        <div className="note">after {money(plan.liabilities || 0)} debts and costs</div>
      </div>
      <div className="tile">
        <div className="label">Passes outside the estate</div>
        <div className="value">{money(o.outsideEstate)}</div>
        <div className="note">nominee, joint, trust, hibah</div>
      </div>
    </div>
  );
}

export function RouteBox({ route }) {
  if (!route) return null;
  return (
    <div className="hint mt">
      <b>Likely route to settle the estate: {route.title}.</b> {route.detail}
    </div>
  );
}

export function Flags({ flags }) {
  if (!flags.length) return <p className="hint">No issues found with the information entered so far.</p>;
  return (
    <ul className="flags">
      {flags.map((f, i) => (
        <li key={i} className={`flag ${f.level}`}>
          <b>{FLAG_TAG[f.level]}</b>
          <span>{f.text}</span>
        </li>
      ))}
    </ul>
  );
}

function Breakdown({ title, entry }) {
  return (
    <div>
      <b>{title}</b>
      {!entry || !entry.contributions.length ? (
        <div className="muted">Nothing</div>
      ) : (
        entry.contributions.map((c) => (
          <div className="kv" key={c.label}>
            <span>{c.label}</span>
            <span>{money(c.amount)}</span>
          </div>
        ))
      )}
    </div>
  );
}

export function Bars({ plan, outcome, open = false }) {
  const willInPlace = outcome.willInPlace;
  const current = outcome.current;
  const fallback = outcome.defaultLaw;
  const sumCurrent = current.people.reduce((sum, p) => sum + p.total, 0);
  const sumDefault = fallback.people.reduce((sum, p) => sum + p.total, 0);
  const scale = Math.max(1, ...current.people.map((p) => p.total), ...(willInPlace ? fallback.people.map((p) => p.total) : []));
  const currentName = plan.muslim ? "Current plan (with wasiat)" : "Current plan (with will)";

  if (!plan.people.length && !plan.assets.length) return <p className="hint">Add family and assets to see the outcome.</p>;

  const rows = current.people.filter((p) => p.total > 0 || p.person.relationship !== "other");
  return (
    <div>
      {willInPlace && (
        <div className="legend" aria-label="Legend">
          <span>
            <i style={{ background: "var(--series-1)" }} />
            {currentName}
          </span>
          <span>
            <i style={{ background: "var(--series-2)" }} />
            If the default law applied ({fallback.law})
          </span>
        </div>
      )}
      <div className="bars">
        {rows.map((row) => {
          const def = fallback.people.find((p) => p.person.id === row.person.id);
          const defTotal = def?.total ?? 0;
          const diff = row.total - defTotal;
          return (
            <details className="bar-person" open={open} key={row.person.id}>
              <summary>
                <div className="bar-name">
                  <div>
                    {personName(row.person)} <span>{REL_SHORT[row.person.relationship]}{row.person.alive ? "" : " (deceased)"}</span>
                  </div>
                  {willInPlace && Math.abs(diff) > 0.5 && (
                    <span>
                      {diff > 0 ? "+" : "-"}
                      {money(Math.abs(diff))} vs default
                    </span>
                  )}
                </div>
                <div className="bar-line" title={`${currentName}: ${money(row.total)}`}>
                  <div className="bar-track">
                    <div className="bar-fill cur" style={{ width: `${(row.total / scale) * 100}%`, minWidth: row.total > 0 ? 2 : 0 }} />
                  </div>
                  <div className="bar-val">
                    <b>{money(row.total)}</b> · {sumCurrent ? percent(row.total / sumCurrent) : "0%"}
                  </div>
                </div>
                {willInPlace && (
                  <div className="bar-line" title={`Default law: ${money(defTotal)}`}>
                    <div className="bar-track">
                      <div className="bar-fill def" style={{ width: `${(defTotal / scale) * 100}%`, minWidth: defTotal > 0 ? 2 : 0 }} />
                    </div>
                    <div className="bar-val">
                      <b>{money(defTotal)}</b> · {sumDefault ? percent(defTotal / sumDefault) : "0%"}
                    </div>
                  </div>
                )}
              </summary>
              <div className="breakdown">
                <Breakdown title={willInPlace ? currentName : "Where it comes from"} entry={row} />
                {willInPlace && <Breakdown title="Default law" entry={def} />}
              </div>
            </details>
          );
        })}
      </div>
      {current.unassigned > 0.5 && <p className="hint mt">{money(current.unassigned)} has no identified recipient under the rules modelled here.</p>}
    </div>
  );
}

export function DefaultLaw({ plan, outcome }) {
  const shares = outcome.defaultLaw.lawShares;
  const people = new Map(plan.people.map((p) => [p.id, p]));
  return (
    <div>
      <p className="sub">
        Without a {plan.muslim ? "wasiat" : "will"}, the estate follows <b>{outcome.defaultLaw.law}</b>.
      </p>
      {shares.length > 0 && (
        <div className="table-scroll mt">
          <table>
            <thead>
              <tr>
                <th>Person</th>
                <th className="num">Share</th>
                <th>Basis</th>
              </tr>
            </thead>
            <tbody>
              {shares.map((s) => {
                const person = people.get(s.personId);
                return (
                  <tr key={s.personId}>
                    <td>{person ? personName(person) : "Unknown"}</td>
                    <td className="num">
                      {fraction(s.fraction)} ({percent(s.fraction)})
                    </td>
                    <td>{s.basis}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      {outcome.defaultLaw.notes.map((n) => (
        <p className="hint mt" key={n}>
          {n}
        </p>
      ))}
    </div>
  );
}

export function CompareTable({ outcome }) {
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            <th>Person</th>
            <th className="num">Current plan</th>
            <th className="num">Default law</th>
            <th className="num">Difference</th>
          </tr>
        </thead>
        <tbody>
          {outcome.current.people.map((row) => {
            const def = outcome.defaultLaw.people.find((p) => p.person.id === row.person.id)?.total ?? 0;
            const diff = row.total - def;
            return (
              <tr key={row.person.id}>
                <td>{personName(row.person)}</td>
                <td className="num">{money(row.total)}</td>
                <td className="num">{money(def)}</td>
                <td className="num">{Math.abs(diff) < 0.5 ? "-" : `${diff > 0 ? "+" : "-"}${money(Math.abs(diff))}`}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
