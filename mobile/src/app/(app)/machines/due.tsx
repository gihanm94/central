import { useQuery } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';

import type { DueItem } from '@/api/types';
import { Badge, EmptyState, ErrorView, ListItem, Loading, Screen, Section, Segmented } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate } from '@/lib/format';
import { api } from '@/state/session';

export default function DueScreen() {
  const t = useT();
  const lang = useLanguage();
  const [days, setDays] = useState(30);
  const q = useQuery({ queryKey: ['machines', 'due', days], queryFn: () => api.machines.due(days) });

  const row = (d: DueItem) => (
    <ListItem
      key={`${d.machine_code}-${d.id}`}
      title={`${d.machine_code}${d.machine_name ? ` · ${d.machine_name}` : ''}`}
      subtitle={`${t('machines.dueOn')} ${formatDate(d.due_date, lang)}${d.maintenance_type ? ` · ${d.maintenance_type}` : ''}`}
      right={d.overdue ? <Badge label={t('machines.overdue')} tone="danger" /> : undefined}
      onPress={() => router.push(`/machines/m/${encodeURIComponent(d.machine_code)}`)}
    />
  );

  return (
    <>
      <Stack.Screen options={{ title: t('machines.due') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Segmented
          options={[7, 30, 90].map((n) => ({ value: n, label: t('machines.days', { n }) }))}
          value={days}
          onChange={setDays}
        />
        {q.isPending ? (
          <Loading />
        ) : q.isError ? (
          <ErrorView error={q.error} onRetry={() => void q.refetch()} />
        ) : (
          <>
            {q.data.data.maintenance && (
              <Section title={t('machines.maintenance')}>
                {q.data.data.maintenance.length ? q.data.data.maintenance.map(row) : <EmptyState title={t('machines.nothingDue')} />}
              </Section>
            )}
            {q.data.data.calibration && (
              <Section title={t('machines.calibration')}>
                {q.data.data.calibration.length ? q.data.data.calibration.map(row) : <EmptyState title={t('machines.nothingDue')} />}
              </Section>
            )}
          </>
        )}
      </Screen>
    </>
  );
}
