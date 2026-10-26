import { useRef, useState } from "react";
import { newPlan, normalizePlan } from "../model.js";
import { samplePlans } from "../samples.js";
import { uid } from "../format.js";
import { useStore } from "../store.js";
import { Field } from "./controls.jsx";
import { ProfileTab } from "./ProfileTab.jsx";
import { ChecklistTab } from "./ChecklistTab.jsx";
import { AssetsTab } from "./AssetsTab.jsx";
import { OutcomeTab } from "./OutcomeTab.jsx";
import { ReportTab, DISCLAIMER } from "./ReportTab.jsx";

/* global __BUILD_TIME__ */
const BUILT = (() => {
  try {
    return new Date(__BUILD_TIME__).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
  } catch {
    return "unknown";
  }
})();

const TABS = [
  { id: "profile", label: "Profile" },
  { id: "checklist", label: "Checklist" },
  { id: "assets", label: "Assets" },
  { id: "outcome", label: "Outcome" },
  { id: "report", label: "Report" },
];

function Logo() {
  return (
    <svg width="30" height="30" viewBox="0 0 32 32" aria-hidden>
      <rect width="32" height="32" rx="7" fill="#17606b" />
      <path d="M16 6c-4 4-6 7-6 10a6 6 0 0 0 12 0c0-3-2-6-6-10z" fill="none" stroke="#e8c877" strokeWidth="2" strokeLinejoin="round" />
      <path d="M16 14v12" stroke="#e8c877" strokeWidth="2" strokeLinecap="round" />
    </svg>
  );
}

function NewProfileForm({ onCreate }) {
  const [name, setName] = useState("");
  const [muslim, setMuslim] = useState(false);
  return (
    <div className="stack" style={{ textAlign: "left", maxWidth: 420, margin: "18px auto 0" }}>
      <Field label="Profile name">
        <input type="text" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Ahmad bin Ismail" />
      </Field>
      <label className="check">
        <input type="checkbox" checked={muslim} onChange={(e) => setMuslim(e.target.checked)} />
        Muslim (faraid applies)
      </label>
      <button type="button" className="btn primary" onClick={() => onCreate(newPlan(name.trim() || "New profile", muslim))}>
        Create profile
      </button>
    </div>
  );
}

export function App() {
  const store = useStore();
  const [tab, setTab] = useState("profile");
  const [creating, setCreating] = useState(false);
  const fileInput = useRef(null);
  const { active, plans } = store;

  const create = (plan) => {
    store.addPlans([plan]);
    setCreating(false);
    setTab("profile");
  };

  const exportAll = () => {
    const blob = new Blob([JSON.stringify({ plans }, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `legacy-planner-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
  };

  const importFile = async (file) => {
    try {
      const data = JSON.parse(await file.text());
      const imported = (data.plans ?? []).map((p) => normalizePlan({ ...p, id: uid() }));
      if (!imported.length) throw new Error("No profiles found in the file.");
      store.addPlans(imported);
    } catch (err) {
      window.alert(`Could not import that file. ${err instanceof Error ? err.message : ""}`);
    }
  };

  const showWelcome = plans.length === 0 || creating;

  return (
    <>
      <header className="app-header no-print">
        <div className="wrap">
          <div className="brand">
            <Logo />
            <div>
              Legacy Planner
              <small>Estate and legacy planning for Malaysia, including assets held abroad</small>
            </div>
          </div>
          {plans.length > 0 && (
            <div className="header-tools">
              <select
                aria-label="Profile"
                value={active?.id ?? ""}
                onChange={(e) => {
                  store.select(e.target.value);
                  setCreating(false);
                }}
              >
                {plans.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.clientName || "Unnamed profile"}
                  </option>
                ))}
              </select>
              <button type="button" className="btn" onClick={() => setCreating(true)}>
                + New profile
              </button>
              <button type="button" className="btn" onClick={exportAll}>
                Export
              </button>
              <button type="button" className="btn" onClick={() => fileInput.current?.click()}>
                Import
              </button>
              <input
                ref={fileInput}
                type="file"
                accept="application/json,.json"
                hidden
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) importFile(file);
                  e.target.value = "";
                }}
              />
            </div>
          )}
        </div>
      </header>

      {!showWelcome && active && (
        <nav className="tabs no-print" aria-label="Sections">
          <div className="wrap" role="tablist">
            {TABS.map((t) => (
              <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} onClick={() => setTab(t.id)}>
                {t.label}
              </button>
            ))}
          </div>
        </nav>
      )}

      <main>
        <div className="wrap">
          {showWelcome ? (
            <section className="card empty">
              <h2>{plans.length === 0 ? "Welcome to Legacy Planner" : "New profile"}</h2>
              <p className="sub" style={{ maxWidth: 520, margin: "0 auto" }}>
                Record what a Malaysian has in place (will, insurance nominations, trusts, EPF and more) — including any assets held outside Malaysia, such as Singapore CPF savings — then see who would receive what, compared with the default law.
              </p>
              <NewProfileForm onCreate={create} />
              <div className="row mt" style={{ justifyContent: "center" }}>
                <button
                  type="button"
                  className="btn"
                  onClick={() => {
                    store.addPlans(samplePlans());
                    setCreating(false);
                    setTab("outcome");
                  }}
                >
                  Load sample profiles
                </button>
                {creating && plans.length > 0 && (
                  <button type="button" className="btn ghost" onClick={() => setCreating(false)}>
                    Cancel
                  </button>
                )}
              </div>
              <p className="sub mt">Profile data stays in this browser. Use Export to back it up or move it.</p>
            </section>
          ) : (
            active && (
              <>
                {tab === "profile" && <ProfileTab plan={active} update={store.updateActive} />}
                {tab === "checklist" && <ChecklistTab plan={active} update={store.updateActive} />}
                {tab === "assets" && <AssetsTab plan={active} update={store.updateActive} />}
                {tab === "outcome" && <OutcomeTab plan={active} />}
                {tab === "report" && <ReportTab plan={active} />}
                <div className="row spread mt no-print">
                  <span />
                  <button
                    type="button"
                    className="btn ghost small danger"
                    onClick={() => {
                      if (window.confirm(`Delete ${active.clientName || "this profile"}? This cannot be undone.`)) store.removeActive();
                    }}
                  >
                    Delete this profile
                  </button>
                </div>
              </>
            )
          )}
          <p className="disclaimer disclaimer-app">{DISCLAIMER}</p>
          <p className="disclaimer disclaimer-app no-print" style={{ marginTop: 4 }}>
            Version built {BUILT}
          </p>
        </div>
      </main>
    </>
  );
}
