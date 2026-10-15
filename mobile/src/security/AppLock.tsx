import * as LocalAuthentication from 'expo-local-authentication';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { AppState, StyleSheet, Text, View } from 'react-native';

import { colors, font, space } from '@/components/theme';
import { Button } from '@/components/ui';
import { useT } from '@/i18n';
import { createStore, useStore } from '@/lib/store';
import { signOut, useSession } from '@/state/session';
import { useSettings } from '@/state/settings';

import { shouldLockAfterBackground, shouldLockOnStart, shouldLockWhenIdle } from './lockPolicy';

export const lockStore = createStore({ locked: false });
let lastTouch = Date.now();

/** Call from the root view's touch capture. */
export function noteInteraction(): void {
  lastTouch = Date.now();
}

export async function deviceIsSecure(): Promise<boolean> {
  try {
    return (await LocalAuthentication.getEnrolledLevelAsync()) !== LocalAuthentication.SecurityLevel.NONE;
  } catch {
    return false;
  }
}

/**
 * Locks the app behind Face ID / fingerprint / device passcode after inactivity
 * (Profile → Settings, on by default). The content underneath is not rendered while locked.
 */
export function AppLock({ children }: { children: ReactNode }) {
  const t = useT();
  const { status } = useSession();
  const settings = useSettings();
  const { locked } = useStore(lockStore);
  const [secure, setSecure] = useState<boolean | null>(null);
  const backgroundAt = useRef<number | null>(null);
  const started = useRef(false);
  const authenticating = useRef(false);

  useEffect(() => {
    void deviceIsSecure().then(setSecure);
  }, []);

  const policy = { enabled: settings.appLock, signedIn: status === 'signedIn', deviceSecure: secure === true, lockAfterMinutes: settings.lockAfterMinutes };

  // cold start
  useEffect(() => {
    if (started.current || secure === null || status === 'loading') return;
    started.current = true;
    if (shouldLockOnStart(policy)) lockStore.set({ locked: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [secure, status]);

  // background → foreground
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'background') {
        backgroundAt.current = Date.now();
      } else if (state === 'active' && backgroundAt.current !== null) {
        const away = Date.now() - backgroundAt.current;
        backgroundAt.current = null;
        if (shouldLockAfterBackground(policy, away)) lockStore.set({ locked: true });
        void deviceIsSecure().then(setSecure); // passcode may have been removed meanwhile
      }
    });
    return () => sub.remove();
  });

  // idle in the foreground
  useEffect(() => {
    const id = setInterval(() => {
      if (AppState.currentState === 'active' && shouldLockWhenIdle(policy, Date.now() - lastTouch)) lockStore.set({ locked: true });
    }, 15_000);
    return () => clearInterval(id);
  });

  // signed out → nothing to protect
  useEffect(() => {
    if (status === 'signedOut') lockStore.set({ locked: false });
  }, [status]);

  const unlock = useCallback(async () => {
    if (authenticating.current) return;
    authenticating.current = true;
    try {
      const r = await LocalAuthentication.authenticateAsync({ promptMessage: t('lock.prompt'), cancelLabel: t('common.cancel'), disableDeviceFallback: false });
      if (r.success) {
        noteInteraction();
        lockStore.set({ locked: false });
      }
    } finally {
      authenticating.current = false;
    }
  }, [t]);

  useEffect(() => {
    if (locked && AppState.currentState === 'active') void unlock();
  }, [locked, unlock]);

  if (locked && status === 'signedIn') {
    return (
      <View style={styles.lock} testID="app-locked">
        <Text style={styles.title}>{t('lock.title')}</Text>
        <Text style={styles.text}>{t('lock.message')}</Text>
        <Button title={t('lock.unlock')} onPress={() => void unlock()} style={{ alignSelf: 'stretch' }} />
        <Button title={t('profile.signOut')} variant="ghost" onPress={() => void signOut()} />
      </View>
    );
  }
  return <>{children}</>;
}

const styles = StyleSheet.create({
  lock: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.md },
  title: { fontSize: font.h1, fontWeight: '800', color: colors.text },
  text: { fontSize: font.body, color: colors.muted, textAlign: 'center', marginBottom: space.lg },
});
