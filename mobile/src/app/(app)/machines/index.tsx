import { useQuery } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';

import { Button, ListItem, Screen, Section } from '@/components/ui';
import { useT } from '@/i18n';
import { can } from '@/lib/permissions';
import { api, useSession } from '@/state/session';

export default function MachinesHome() {
  const t = useT();
  const { profile } = useSession();
  const seeChecks = can(profile, 'machines', 'machines_checklists');
  const seeDue = can(profile, 'machines', 'machines_maintenance') || can(profile, 'machines', 'machines_calibration');
  const pending = useQuery({ queryKey: ['machines', 'checklists', { pending: true, count: true }], queryFn: () => api.machines.checklists({ pending: true, per_page: 1 }), enabled: seeChecks });
  const due = useQuery({ queryKey: ['machines', 'due', 30], queryFn: () => api.machines.due(30), enabled: seeDue });
  const dueCount = (due.data?.data.maintenance?.length ?? 0) + (due.data?.data.calibration?.length ?? 0);
  const overdue = [...(due.data?.data.maintenance ?? []), ...(due.data?.data.calibration ?? [])].filter((d) => d.overdue).length;

  return (
    <>
      <Stack.Screen options={{ title: t('machines.title') }} />
      <Screen onRefresh={() => void Promise.all([pending.refetch(), due.refetch()])} refreshing={pending.isRefetching || due.isRefetching}>
        <Section>
          <Button title={t('machines.scan')} onPress={() => router.push('/machines/scan')} testID="scan" />
          <Button title={t('machines.search')} variant="secondary" onPress={() => router.push('/machines/search')} style={{ marginTop: 8 }} />
        </Section>
        <Section>
          {seeChecks && <ListItem title={t('machines.myChecks')} onPress={() => router.push('/machines/checks')} />}
          {seeChecks && (
            <ListItem
              title={t('machines.waiting')}
              subtitle={pending.data ? t('machines.waitingCount', { n: pending.data.meta.total }) : null}
              onPress={() => router.push('/machines/checks?pending=1')}
              testID="waiting"
            />
          )}
          {seeDue && (
            <ListItem
              title={t('machines.due')}
              subtitle={due.data ? t('machines.dueCount', { n: dueCount, late: overdue }) : null}
              onPress={() => router.push('/machines/due')}
            />
          )}
        </Section>
      </Screen>
    </>
  );
}
