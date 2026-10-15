import { Stack } from 'expo-router';

import { colors } from '@/components/theme';
import { useT } from '@/i18n';

export default function ProfileLayout() {
  const t = useT();
  return <Stack screenOptions={{ headerTintColor: colors.primary, headerTitleStyle: { color: colors.text }, headerBackTitle: t('common.back'), contentStyle: { backgroundColor: colors.bg } }} />;
}
