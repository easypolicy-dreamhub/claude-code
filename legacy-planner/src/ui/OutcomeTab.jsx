import { analyse } from "../engine/outcome.js";
import { Bars, CompareTable, DefaultLaw, Flags, RouteBox, Tiles } from "./OutcomeParts.jsx";

export function OutcomeTab({ plan }) {
  const outcome = analyse(plan);
  const highCount = outcome.flags.filter((f) => f.level === "high").length;
  const doc = plan.muslim ? "wasiat" : "will";

  return (
    <div className="stack">
      <section className="card">
        <h2>If {plan.clientName || "this person"} passed away today</h2>
        <p className="sub">
          {outcome.willInPlace
            ? `Based on the ${doc} marked Done, the nominations and the way each asset is held.`
            : `No ${doc} is marked Done, so the estate follows the default law.`}
        </p>
        <div className="mt">
          <Tiles plan={plan} outcome={outcome} />
        </div>
        <RouteBox route={outcome.route} />
      </section>

      <section className="card">
        <h2>Who receives what</h2>
        <p className="sub">Tap a person for the breakdown by source.</p>
        <Bars plan={plan} outcome={outcome} />
        <details className="mt">
          <summary className="sub" style={{ cursor: "pointer" }}>
            Show as a table
          </summary>
          <div className="mt">
            <CompareTable outcome={outcome} />
          </div>
        </details>
      </section>

      <section className="card">
        <div className="row spread">
          <h2>Gaps and things to check</h2>
          {highCount > 0 && <span className="pill gold">{highCount} to act on</span>}
        </div>
        <Flags flags={outcome.flags} />
      </section>

      <section className="card">
        <h2>The default law</h2>
        <DefaultLaw plan={plan} outcome={outcome} />
      </section>
    </div>
  );
}
