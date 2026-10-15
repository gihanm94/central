import { useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { Text, View } from 'react-native';

import { Banner, Button, Card, ErrorView, Field, ListItem, Loading, Screen, Section, Title } from '@/components/ui';
import { CheckStatusBadge, MachineStatusBadge } from '@/features/machines/components';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatDateTime, parseDate } from '@/lib/format';
import { api } from '@/state/session';

export default function MachineScreen() {
  const t = useT();
  const lang = useLanguage();
  const { code } = useLocalSearchParams<{ code: string }>();
  const q = useQuery({ queryKey: ['machines', 'machine', code], queryFn: () => api.machines.machine(code) });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const { machine: m, people, checklist, last_checks, next_maintenance, next_calibration } = q.data.data;
  const late = (d: string | null) => {
    const x = parseDate(d);
    return !!x && x.getTime() < Date.now() - 86_400_000;
  };

  return (
    <>
      <Stack.Screen options={{ title: m.machine_code }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={[m.machine_group_name, m.machine_type_name].filter(Boolean).join(' · ')}>{m.machine_name}</Title>
        <View style={{ flexDirection: 'row', gap: 8, marginBottom: 12 }}>
          <MachineStatusBadge status={m.machine_status} />
          <CheckStatusBadge status={m.check_status} />
        </View>
        {!!m.image_url && <Image source={{ uri: m.image_url }} style={{ height: 180, borderRadius: 12, marginBottom: 12 }} contentFit="cover" cachePolicy="memory" />}

        <Section title={t('machines.today')}>
          {!checklist ? (
            <Banner tone="neutral" text={t('machines.notInUse')} />
          ) : checklist.can_check ? (
            <>
              {checklist.recheck && <Banner tone="info" text={t('machines.recheckInfo')} />}
              <Button title={t('machines.doCheck', { n: checklist.items.length })} onPress={() => router.push(`/machines/m/${encodeURIComponent(m.machine_code)}/check`)} testID="do-check" />
            </>
          ) : (
            <Banner tone="neutral" text={checklist.items.length === 0 ? t('machines.noQuestions') : t('machines.noPermission')} />
          )}
        </Section>

        <Section title={t('machines.details')}>
          <Card>
            <Field label={t('machines.brandModel')} value={[m.brand, m.model].filter(Boolean).join(' ')} />
            <Field label={t('machines.serial')} value={m.serial_number} />
            <Field label={t('machines.number')} value={m.machine_number} />
            <Field label={t('machines.responsible')} value={people.responsible?.name} />
            <Field label={t('machines.supervisor')} value={people.supervisor?.name} />
            <Field label={t('machines.manager')} value={people.manager?.name} />
            <Field label={t('machines.nextMaintenance')} value={next_maintenance ? `${formatDate(next_maintenance, lang)}${late(next_maintenance) ? ` · ${t('machines.overdue')}` : ''}` : null} />
            {m.is_calibration && <Field label={t('machines.nextCalibration')} value={next_calibration ? `${formatDate(next_calibration, lang)}${late(next_calibration) ? ` · ${t('machines.overdue')}` : ''}` : null} />}
            {!!m.note && <Field label={t('common.note')} value={m.note} />}
          </Card>
        </Section>

        <Section title={t('machines.lastChecks')}>
          {last_checks.length === 0 && <Text style={{ color: '#6B7280' }}>{t('machines.noChecks')}</Text>}
          {last_checks.map((c) => (
            <ListItem
              key={c.id}
              title={formatDateTime(c.created_at, lang)}
              subtitle={[c.user_name, c.recheck ? t('machines.recheck') : t('machines.general')].filter(Boolean).join(' · ')}
              right={<CheckStatusBadge status={c.checklist_status} />}
              onPress={() => router.push(`/machines/check/${c.id}`)}
            />
          ))}
        </Section>
      </Screen>
    </>
  );
}
