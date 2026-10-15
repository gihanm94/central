import { Redirect, Stack } from 'expo-router';
import { View } from 'react-native';

import type { ModuleKey } from '@/api/types';
import { useT } from '@/i18n';
import { hasModule } from '@/lib/permissions';
import { useModuleUnavailable } from '@/state/moduleHealth';
import { useSession } from '@/state/session';

import { colors } from './theme';
import { Banner } from './ui';

/** Layout of a module: only for people whose /me lists it; shows a banner while it answers 503. */
export function ModuleStack({ module }: { module: ModuleKey }) {
  const t = useT();
  const { profile } = useSession();
  const unavailable = useModuleUnavailable(module);
  if (profile && !hasModule(profile, module)) return <Redirect href="/" />;
  return (
    <View style={{ flex: 1 }}>
      {unavailable && <Banner tone="warn" text={t('errors.module_unavailable')} testID={`unavailable-${module}`} />}
      <Stack
        screenOptions={{
          headerTintColor: colors.primary,
          headerTitleStyle: { color: colors.text },
          headerBackTitle: t('common.back'),
          contentStyle: { backgroundColor: colors.bg },
        }}
      />
    </View>
  );
}
