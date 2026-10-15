import { useMutation, useQuery } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { Alert } from 'react-native';

import type { Device } from '@/api/types';
import { Badge, Banner, Button, EmptyState, ErrorView, ListItem, Loading, Screen } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { formatDateTime } from '@/lib/format';
import { api, queryClient, signOut } from '@/state/session';

/** My signed-in phones: sign out a lost one. */
export default function DevicesScreen() {
  const t = useT();
  const lang = useLanguage();
  const q = useQuery({ queryKey: ['me', 'devices'], queryFn: () => api.me.devices() });
  const revoke = useMutation({
    mutationFn: (id: number) => api.me.revokeDevice(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['me', 'devices'] }),
  });

  function ask(d: Device) {
    if (d.current) {
      Alert.alert(t('profile.signOutTitle'), t('profile.signOutText'), [
        { text: t('common.cancel'), style: 'cancel' },
        { text: t('profile.signOut'), style: 'destructive', onPress: () => void signOut() },
      ]);
      return;
    }
    Alert.alert(t('devices.revokeTitle', { name: d.name }), t('devices.revokeText'), [
      { text: t('common.cancel'), style: 'cancel' },
      { text: t('devices.revoke'), style: 'destructive', onPress: () => revoke.mutate(d.id) },
    ]);
  }

  return (
    <>
      <Stack.Screen options={{ title: t('profile.devices') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        {revoke.isError && <Banner tone="danger" text={errorMessage(revoke.error, t)} />}
        {q.isPending ? (
          <Loading />
        ) : q.isError ? (
          <ErrorView error={q.error} onRetry={() => void q.refetch()} />
        ) : q.data.data.length === 0 ? (
          <EmptyState title={t('common.nothing')} />
        ) : (
          q.data.data.map((d) => (
            <ListItem
              key={d.id}
              testID={`device-${d.id}`}
              title={d.name}
              subtitle={t('devices.lastUsed', { at: formatDateTime(d.last_used_at, lang), ip: d.last_ip ?? '—' })}
              meta={t('devices.signedIn', { at: formatDateTime(d.created_at, lang), until: formatDateTime(d.expires_at, lang) })}
              right={
                d.current ? (
                  <Badge label={t('devices.thisPhone')} tone="success" />
                ) : (
                  <Button title={t('devices.revoke')} variant="danger" small onPress={() => ask(d)} loading={revoke.isPending && revoke.variables === d.id} />
                )
              }
            />
          ))
        )}
      </Screen>
    </>
  );
}
