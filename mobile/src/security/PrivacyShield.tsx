import * as ScreenCapture from 'expo-screen-capture';
import { useEffect, useState } from 'react';
import { AppState, Platform, StyleSheet, Text, View } from 'react-native';

import { colors, font } from '@/components/theme';

/**
 * Hides the app's content in the app switcher:
 *  - iOS: system blur over the snapshot (enableAppSwitcherProtectionAsync) plus our own cover while inactive
 *  - Android: FLAG_SECURE (preventScreenCaptureAsync) keeps the recents thumbnail blank and blocks screenshots
 */
export function PrivacyShield() {
  const [hidden, setHidden] = useState(AppState.currentState !== 'active');

  useEffect(() => {
    if (Platform.OS === 'android') {
      void ScreenCapture.preventScreenCaptureAsync('privacy').catch(() => undefined);
    } else if (Platform.OS === 'ios') {
      void ScreenCapture.enableAppSwitcherProtectionAsync(0.9).catch(() => undefined);
    }
    const sub = AppState.addEventListener('change', (s) => setHidden(s !== 'active'));
    return () => sub.remove();
  }, []);

  if (!hidden) return null;
  return (
    <View style={StyleSheet.absoluteFill} pointerEvents="none" testID="privacy-shield">
      <View style={styles.cover}>
        <Text style={styles.brand}>Acme</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  cover: { flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' },
  brand: { fontSize: font.h1, fontWeight: '800', color: colors.primary },
});
