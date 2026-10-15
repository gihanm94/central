/** Pure rules of the app lock (tested in __tests__/lockPolicy.test.ts). */
export interface LockInput {
  enabled: boolean;
  signedIn: boolean;
  /** The phone has a passcode / biometrics to unlock with. */
  deviceSecure: boolean;
  lockAfterMinutes: number;
}

/** Back from the background after `awayMs`: lock? 0 minutes = every time. */
export function shouldLockAfterBackground(p: LockInput, awayMs: number): boolean {
  if (!p.enabled || !p.signedIn || !p.deviceSecure) return false;
  return awayMs >= p.lockAfterMinutes * 60_000;
}

/** Untouched in the foreground for `idleMs`: lock? Never sooner than one minute. */
export function shouldLockWhenIdle(p: LockInput, idleMs: number): boolean {
  if (!p.enabled || !p.signedIn || !p.deviceSecure) return false;
  return idleMs >= Math.max(1, p.lockAfterMinutes) * 60_000;
}

/** Cold start with a stored sign-in: lock straight away. */
export function shouldLockOnStart(p: LockInput): boolean {
  return p.enabled && p.signedIn && p.deviceSecure;
}
