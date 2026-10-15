import { Stack } from 'expo-router';

import { colors } from '@/components/theme';
import { useT } from '@/i18n';

/** Everything behind sign-in. Modules have their own stacks (and headers). */
export default function AppLayout() {
  const t = useT();
  return (
    <Stack
      screenOptions={{
        headerTintColor: colors.primary,
        headerTitleStyle: { color: colors.text },
        headerBackTitle: t('common.back'),
        contentStyle: { backgroundColor: colors.bg },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Acme' }} />
      <Stack.Screen name="crm" options={{ headerShown: false }} />
      <Stack.Screen name="machines" options={{ headerShown: false }} />
      <Stack.Screen name="accounting" options={{ headerShown: false }} />
      <Stack.Screen name="profile" options={{ headerShown: false }} />
    </Stack>
  );
}
