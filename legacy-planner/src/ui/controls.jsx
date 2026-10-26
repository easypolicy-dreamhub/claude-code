import { useLayoutEffect, useRef } from "react";
import { personLabel } from "../labels.js";

export function Field({ label, children }) {
  return (
    <label className="field">
      {label}
      {children}
    </label>
  );
}

export function Group({ label, children }) {
  return (
    <div className="field" role="group" aria-label={label}>
      <span>{label}</span>
      {children}
    </div>
  );
}

// Amount in whole ringgit, shown with thousand separators (60,000) as you type.
export function MoneyInput({ value, onChange, ariaLabel }) {
  const ref = useRef(null);
  const digitsBeforeCaret = useRef(null);
  const shown = value ? Math.round(Number(value)).toLocaleString("en-US") : "";

  // After React re-renders with the formatted text, put the caret back after the same number of digits.
  useLayoutEffect(() => {
    const input = ref.current;
    if (digitsBeforeCaret.current !== null && input && document.activeElement === input) {
      let pos = 0;
      let seen = 0;
      while (pos < input.value.length && seen < digitsBeforeCaret.current) {
        if (/\d/.test(input.value[pos])) seen++;
        pos++;
      }
      input.setSelectionRange(pos, pos);
    }
    digitsBeforeCaret.current = null;
  });

  return (
    <input
      ref={ref}
      type="text"
      inputMode="numeric"
      autoComplete="off"
      aria-label={ariaLabel}
      value={shown}
      placeholder="0"
      onChange={(e) => {
        const raw = e.target.value;
        const caret = e.target.selectionStart ?? raw.length;
        digitsBeforeCaret.current = raw.slice(0, caret).replace(/\D/g, "").length;
        onChange(Number(raw.replace(/\D/g, "")) || 0);
      }}
    />
  );
}

export function Seg({ value, options, onChange }) {
  return (
    <div className="seg" role="group">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          aria-pressed={value === o.value}
          className={o.value === "na" ? "s-na" : undefined}
          onClick={() => onChange(o.value)}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

// Percentage split between people: used for will gifts, nominations, trusts and hibah.
export function Allocator({ people, value, onChange }) {
  const total = value.reduce((sum, a) => sum + (Number(a.pct) || 0), 0);
  const isHundred = Math.abs(total - 100) < 0.01;
  const patch = (index, change) => onChange(value.map((a, i) => (i === index ? { ...a, ...change } : a)));
  const splitEqually = () => {
    if (!value.length) return;
    const each = Math.floor(10000 / value.length) / 100;
    const extra = Math.round((100 - each * value.length) * 100) / 100;
    onChange(value.map((a, i) => ({ ...a, pct: i === 0 ? Math.round((each + extra) * 100) / 100 : each })));
  };

  return (
    <div className="alloc">
      {value.map((a, i) => (
        <div className="alloc-row" key={i}>
          <select aria-label="Beneficiary" value={a.personId} onChange={(e) => patch(i, { personId: e.target.value })}>
            <option value="">Choose person</option>
            {people.map((p) => (
              <option key={p.id} value={p.id}>
                {personLabel(p)}
                {p.alive ? "" : " - deceased"}
              </option>
            ))}
          </select>
          <div className="row" style={{ flexWrap: "nowrap", gap: 4 }}>
            <input
              type="number"
              inputMode="decimal"
              aria-label="Percent"
              min={0}
              max={100}
              step="any"
              value={a.pct === 0 ? "" : a.pct}
              placeholder="0"
              onChange={(e) => patch(i, { pct: Math.max(0, Number(e.target.value) || 0) })}
            />
            <span aria-hidden>%</span>
          </div>
          <button type="button" className="btn ghost small danger" aria-label="Remove beneficiary" onClick={() => onChange(value.filter((_, x) => x !== i))}>
            Remove
          </button>
        </div>
      ))}
      <div className="row spread">
        <div className="row">
          <button
            type="button"
            className="btn small"
            disabled={!people.length}
            onClick={() => onChange([...value, { personId: people[0]?.id ?? "", pct: 0 }])}
          >
            + Add beneficiary
          </button>
          {value.length > 1 && (
            <button type="button" className="btn small ghost" onClick={splitEqually}>
              Split equally
            </button>
          )}
        </div>
        {value.length > 0 && <span className={isHundred ? "total-ok" : "total-bad"}>Total {Math.round(total * 100) / 100}%</span>}
      </div>
      {!people.length && <p className="hint">Add people in the Profile tab first.</p>}
    </div>
  );
}
