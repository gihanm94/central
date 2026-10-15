import { shouldLockAfterBackground, shouldLockOnStart, shouldLockWhenIdle } from '../lockPolicy';

const on = { enabled: true, signedIn: true, deviceSecure: true, lockAfterMinutes: 1 };

describe('lock policy', () => {
  it('locks after the chosen time in the background', () => {
    expect(shouldLockAfterBackground(on, 59_000)).toBe(false);
    expect(shouldLockAfterBackground(on, 60_000)).toBe(true);
    expect(shouldLockAfterBackground({ ...on, lockAfterMinutes: 0 }, 1)).toBe(true);
  });

  it('locks when untouched, never sooner than a minute', () => {
    expect(shouldLockWhenIdle({ ...on, lockAfterMinutes: 0 }, 30_000)).toBe(false);
    expect(shouldLockWhenIdle({ ...on, lockAfterMinutes: 5 }, 4 * 60_000)).toBe(false);
    expect(shouldLockWhenIdle({ ...on, lockAfterMinutes: 5 }, 5 * 60_000)).toBe(true);
  });

  it('locks at start with a stored sign-in', () => {
    expect(shouldLockOnStart(on)).toBe(true);
  });

  it('never locks when off, signed out, or the phone has no passcode', () => {
    for (const p of [{ ...on, enabled: false }, { ...on, signedIn: false }, { ...on, deviceSecure: false }]) {
      expect(shouldLockOnStart(p)).toBe(false);
      expect(shouldLockAfterBackground(p, 1e9)).toBe(false);
      expect(shouldLockWhenIdle(p, 1e9)).toBe(false);
    }
  });
});
