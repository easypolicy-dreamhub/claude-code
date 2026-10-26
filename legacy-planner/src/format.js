export const money = (v) => `${v < 0 ? "-" : ""}RM ${Math.abs(Math.round(v)).toLocaleString("en-US")}`;

export function percent(v, digits = 1) {
  return `${(v * 100).toFixed(digits).replace(/\.0+$/, "")}%`;
}

// Shows a share as a simple fraction (1/3, 2/3 ...) when it is one, otherwise as a percentage.
export function fraction(v) {
  for (let d = 1; d <= 120; d++) {
    const n = Math.round(v * d);
    if (n > 0 && Math.abs(n / d - v) < 1e-9) return `${n}/${d}`;
  }
  return `${(v * 100).toFixed(1)}%`;
}

export const uid = () =>
  typeof crypto !== "undefined" && "randomUUID" in crypto
    ? crypto.randomUUID()
    : Math.random().toString(36).slice(2) + Date.now().toString(36);
