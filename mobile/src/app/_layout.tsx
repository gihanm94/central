import { QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { colors } from '@/components/theme';
import { Loading } from '@/components/ui';
import { useT } from '@/i18n';
import { noteInteraction } from '@/security/activity';
import { AppLock } from '@/security/AppLock';
import { PrivacyShield } from '@/security/PrivacyShield';
import { bootstrap, queryClient, useProfileRefresh, useSession } from '@/state/session';

export default function RootLayout() {
  const t = useT();
  const [ready, setReady] = useState(false);
  const { status } = useSession();
  useProfileRefresh();

  useEffect(() => {
    void bootstrap().finally(() => setReady(true));
  }, []);

  const signedIn = status === 'signedIn';

  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <StatusBar style="dark" />
        <View
          style={{ flex: 1, backgroundColor: colors.bg }}
          onStartShouldSetResponderCapture={() => {
            noteInteraction();
            return false;
          }}
        >
          {!ready || status === 'loading' ? (
            <Loading />
          ) : (
            <AppLock>
              <Stack screenOptions={{ headerTintColor: colors.primary, headerTitleStyle: { color: colors.text }, contentStyle: { backgroundColor: colors.bg } }}>
                <Stack.Protected guard={signedIn}>
                  <Stack.Screen name="(app)" options={{ headerShown: false }} />
                </Stack.Protected>
                <Stack.Protected guard={!signedIn}>
                  <Stack.Screen name="sign-in" options={{ headerShown: false }} />
                </Stack.Protected>
                <Stack.Screen name="server" options={{ title: t('server.title'), presentation: 'modal' }} />
              </Stack>
            </AppLock>
          )}
          <PrivacyShield />
        </View>
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}
