import { useEffect, useState } from 'react';

import { createStore, useStore } from '@/lib/store';

/** Modules whose API answered 503 module_unavailable, until when (ms since epoch). */
export const moduleHealthStore = createStore<Record<string, number>>({});

const HOLD_MS = 60_000;

export function markModuleUnavailable(module: string, now = Date.now()): void {
  moduleHealthStore.set((s) => ({ ...s, [module]: now + HOLD_MS }));
}

export function markModuleAvailable(module: string): void {
  moduleHealthStore.set((s) => {
    if (!(module in s)) return s;
    const { [module]: _gone, ...rest } = s;
    return rest;
  });
}

export function resetModuleHealth(): void {
  moduleHealthStore.set({});
}

export function useModuleUnavailable(module: string): boolean {
  const until = useStore(moduleHealthStore)[module] ?? 0;
  const [, setTick] = useState(0);
  const down = until > Date.now();
  // re-render when the hold runs out, so the banner goes away by itself
  useEffect(() => {
    if (!down) return;
    const id = setTimeout(() => setTick((n) => n + 1), until - Date.now() + 50);
    return () => clearTimeout(id);
  }, [down, until]);
  return down;
}
