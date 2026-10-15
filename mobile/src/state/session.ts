import { QueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';

import { ApiClient } from '@/api/client';
import { createApi } from '@/api/endpoints';
import { ApiError, isApiError } from '@/api/errors';
import type { LoginRequest, Profile } from '@/api/types';
import { languageStore, setProfileLocale, t } from '@/i18n';
import { clearCachedFiles } from '@/lib/files';
import { createStore, useStore } from '@/lib/store';
import { KEYS, deleteSecure, readSecure, secureTokenStore, writeSecure } from '@/security/secureStorage';

import { markModuleAvailable, markModuleUnavailable, resetModuleHealth } from './moduleHealth';
import { currentApiBase, loadSettings } from './settings';

export type SessionStatus = 'loading' | 'signedOut' | 'signedIn';

export interface SessionState {
  status: SessionStatus;
  profile: Profile | null;
  /** Why the person was signed out (shown on the sign-in screen). */
  notice: string | null;
}

export const sessionStore = createStore<SessionState>({ status: 'loading', profile: null, notice: null });

export function useSession(): SessionState {
  return useStore(sessionStore);
}

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      retry: (count, err) => {
        if (!isApiError(err)) return count < 1;
        if (err.isNetwork) return count < 2;
        if (err.status >= 500 && err.status !== 503) return count < 1;
        return false; // 4xx, 503 module_unavailable, 429: no automatic retry
      },
    },
    mutations: { retry: false },
  },
});

export const apiClient = new ApiClient({
  baseUrl: currentApiBase,
  tokens: secureTokenStore,
  language: () => languageStore.get(),
  onSessionEnded: (err) => {
    void endLocally(err.message || t('auth.sessionEnded'));
  },
  onModuleUnavailable: markModuleUnavailable,
  // Development only: method, path without query, status, request id. Never bodies or tokens.
  log: __DEV__ ? (line) => console.log(`[api] ${line}`) : undefined,
});

export const api = createApi(apiClient);

/** App start: settings, then the stored sign-in (if any). */
export async function bootstrap(): Promise<void> {
  await loadSettings();
  if (!(await apiClient.hasSession())) {
    await deleteSecure(KEYS.profile);
    sessionStore.set({ status: 'signedOut', profile: null, notice: null });
    return;
  }
  const cached = await readProfile();
  setProfileLocale(cached?.locale ?? null);
  sessionStore.set({ status: 'signedIn', profile: cached, notice: null });
  void refreshProfile();
}

/** /me again (permissions can change at any time on the website). */
export async function refreshProfile(): Promise<Profile | null> {
  try {
    const profile = (await api.me.get()).data;
    await setProfile(profile);
    return profile;
  } catch (e) {
    if (isApiError(e) && e.endsSession) return null; // handled by onSessionEnded
    return sessionStore.get().profile;
  }
}

export async function signIn(req: LoginRequest): Promise<Profile> {
  const res = await api.auth.login(req);
  queryClient.clear();
  await apiClient.startSession(res);
  await setProfile(res.user);
  sessionStore.set({ status: 'signedIn', profile: res.user, notice: null });
  return res.user;
}

/** Sign out on the server, then forget everything on this phone. */
export async function signOut(): Promise<void> {
  try {
    await api.auth.logout();
  } catch {
    // offline or already signed out: still clear locally
  }
  await endLocally(null);
}

let ending: Promise<void> | null = null;

/** Forget tokens, profile, cached server data and files. */
export function endLocally(notice: string | null): Promise<void> {
  if (!ending) {
    ending = (async () => {
      sessionStore.set({ status: 'signedOut', profile: null, notice });
      await apiClient.clearSession();
      queryClient.cancelQueries();
      queryClient.clear();
      resetModuleHealth();
      setProfileLocale(null);
      await deleteSecure(KEYS.profile);
      await clearCachedFiles();
    })().finally(() => {
      ending = null;
    });
  }
  return ending;
}

export function clearNotice(): void {
  sessionStore.set((s) => ({ ...s, notice: null }));
}

async function setProfile(profile: Profile): Promise<void> {
  setProfileLocale(profile.locale);
  for (const m of profile.modules) markModuleAvailable(m);
  sessionStore.set((s) => (s.status === 'signedIn' ? { ...s, profile } : s));
  await writeSecure(KEYS.profile, JSON.stringify(profile));
}

async function readProfile(): Promise<Profile | null> {
  try {
    const raw = await readSecure(KEYS.profile);
    const p = raw ? (JSON.parse(raw) as Profile) : null;
    return p && typeof p.id === 'number' && Array.isArray(p.modules) ? p : null;
  } catch {
    return null;
  }
}

/** Keep the profile (modules, permissions) fresh while signed in. */
export function useProfileRefresh(intervalMs = 5 * 60_000): void {
  const { status } = useSession();
  useEffect(() => {
    if (status !== 'signedIn') return;
    const id = setInterval(() => void refreshProfile(), intervalMs);
    return () => clearInterval(id);
  }, [status, intervalMs]);
}

export { ApiError };
