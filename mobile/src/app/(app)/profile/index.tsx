import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { Alert } from 'react-native';

import { Button, Card, Field, ListItem, Screen, Section, Title } from '@/components/ui';
import { useT } from '@/i18n';
import { appConfig } from '@/lib/appConfig';
import { signOut, useSession } from '@/state/session';
import { effectiveServerUrl, useSettings } from '@/state/settings';

export default function ProfileScreen() {
  const t = useT();
  const { profile } = useSession();
  const settings = useSettings();
  const [busy, setBusy] = useState(false);

  function confirmSignOut() {
    Alert.alert(t('profile.signOutTitle'), t('profile.signOutText'), [
      { text: t('common.cancel'), style: 'cancel' },
      {
        text: t('profile.signOut'),
        style: 'destructive',
        onPress: () => {
          setBusy(true);
          void signOut();
        },
      },
    ]);
  }

  return (
    <>
      <Stack.Screen options={{ title: t('profile.title') }} />
      <Screen>
        <Title sub={profile?.email}>{profile?.name ?? ''}</Title>
        <Card>
          <Field label={t('profile.role')} value={profile?.role} />
          <Field label={t('crm.department')} value={profile?.department} />
          <Field label={t('profile.team')} value={profile?.team} />
          <Field label={t('profile.jobTitle')} value={profile?.job_title} />
        </Card>
        <Section style={{ marginTop: 16 }}>
          <ListItem title={t('profile.devices')} subtitle={t('profile.devicesHint')} onPress={() => router.push('/profile/devices')} />
          <ListItem title={t('profile.notifications')} onPress={() => router.push('/profile/notifications')} />
          <ListItem title={t('profile.settings')} subtitle={t('profile.settingsHint')} onPress={() => router.push('/profile/settings')} />
        </Section>
        <Button title={t('profile.signOut')} variant="danger" onPress={confirmSignOut} loading={busy} testID="sign-out" />
        <Field label={t('server.label')} value={`${effectiveServerUrl(settings)} · v${appConfig.version}`} />
      </Screen>
    </>
  );
}
