import { useCallback, useEffect, useState } from "react";
import { normalizePlan } from "./model.js";

const KEY = "legacy-planner.v1";
const EMPTY = { plans: [], activeId: null };
let memory = null;

function load() {
  try {
    const raw = window.localStorage.getItem(KEY);
    if (raw) {
      const data = JSON.parse(raw);
      return { plans: (data.plans ?? []).map(normalizePlan), activeId: data.activeId ?? null };
    }
  } catch {
    /* storage unavailable or corrupt: fall through */
  }
  return memory ?? EMPTY;
}

function save(state) {
  memory = state;
  try {
    window.localStorage.setItem(KEY, JSON.stringify(state));
  } catch {
    /* private mode or full: keep going in memory */
  }
}

export function useStore() {
  const [state, setState] = useState(load);
  useEffect(() => save(state), [state]);

  const activeId = state.activeId ?? state.plans[0]?.id ?? null;
  const active = state.plans.find((p) => p.id === activeId) ?? null;

  const updateActive = useCallback((fn) => {
    setState((s) => {
      const id = s.activeId ?? s.plans[0]?.id;
      return { ...s, plans: s.plans.map((p) => (p.id === id ? { ...fn(p), updatedAt: new Date().toISOString() } : p)) };
    });
  }, []);
  const addPlans = useCallback((plans) => setState((s) => ({ plans: [...s.plans, ...plans], activeId: plans[0]?.id ?? s.activeId })), []);
  const select = useCallback((id) => setState((s) => ({ ...s, activeId: id })), []);
  const removeActive = useCallback(() => {
    setState((s) => {
      const id = s.activeId ?? s.plans[0]?.id;
      const plans = s.plans.filter((p) => p.id !== id);
      return { plans, activeId: plans[0]?.id ?? null };
    });
  }, []);

  return { plans: state.plans, active, activeId, updateActive, addPlans, select, removeActive };
}
