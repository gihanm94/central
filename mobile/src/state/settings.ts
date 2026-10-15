import { appConfig } from '@/lib/appConfig';
import { apiBaseFromServerUrl } from '@/lib/serverUrl';
import { createStore, useStore } from '@/lib/store';
import { KEYS, readSecure, writeSecure } from '@/security/secureStorage';

export type LanguageSetting = 'system' | 'en' | 'th';

export interface Settings {
  /** As typed by the person; empty = the build's default server. */
  serverUrl: string;
  language: LanguageSetting;
  /** Ask for Face ID / fingerprint / device passcode after inactivity. */
  appLock: boolean;
  /** Minutes in the background (or untouched) before the lock (0 = every time the app is left). */
  lockAfterMinutes: number;
}

export const LOCK_CHOICES = [0, 1, 5, 15] as const;

const DEFAULTS: Settings = { serverUrl: '', language: 'system', appLock: true, lockAfterMinutes: 1 };

export const settingsStore = createStore<Settings>(DEFAULTS);

export async function loadSettings(): Promise<Settings> {
  const raw = await readSecure(KEYS.settings);
  let parsed: Partial<Settings> = {};
  try {
    parsed = raw ? (JSON.parse(raw) as Partial<Settings>) : {};
  } catch {
    parsed = {};
  }
  const s: Settings = {
    serverUrl: typeof parsed.serverUrl === 'string' ? parsed.serverUrl : DEFAULTS.serverUrl,
    language: parsed.language === 'en' || parsed.language === 'th' ? parsed.language : 'system',
    appLock: typeof parsed.appLock === 'boolean' ? parsed.appLock : DEFAULTS.appLock,
    lockAfterMinutes: (LOCK_CHOICES as readonly number[]).includes(parsed.lockAfterMinutes as number) ? (parsed.lockAfterMinutes as number) : DEFAULTS.lockAfterMinutes,
  };
  settingsStore.set(s);
  return s;
}

export async function updateSettings(patch: Partial<Settings>): Promise<void> {
  settingsStore.set((s) => ({ ...s, ...patch }));
  await writeSecure(KEYS.settings, JSON.stringify(settingsStore.get()));
}

export function useSettings(): Settings {
  return useStore(settingsStore);
}

/** The server address in use (typed one, else the build default). */
export function effectiveServerUrl(s: Settings = settingsStore.get()): string {
  return s.serverUrl.trim() || appConfig.defaultServerUrl;
}

/** API root for the client, or '' when no valid server is set. */
export function currentApiBase(): string {
  const r = apiBaseFromServerUrl(effectiveServerUrl(), { allowInsecureLocal: appConfig.allowInsecureLocal });
  return r.ok ? r.url : '';
}
