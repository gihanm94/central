import { useEffect, useState } from 'react';

/** Seconds left until `until` (ms since epoch), re-rendering every second; 0 when over. */
export function useCountdown(until: number | null): number {
  const [, setTick] = useState(0);
  const left = until ? Math.max(0, Math.ceil((until - Date.now()) / 1000)) : 0;
  useEffect(() => {
    if (!until || left === 0) return;
    const id = setInterval(() => setTick((n) => n + 1), 1000);
    return () => clearInterval(id);
  }, [until, left === 0]); // eslint-disable-line react-hooks/exhaustive-deps
  return left;
}

export function formatSeconds(s: number): string {
  const m = Math.floor(s / 60);
  return m > 0 ? `${m}:${String(s % 60).padStart(2, '0')}` : `${s}s`;
}
