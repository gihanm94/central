import { useMutation, useQuery } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { Badge, Banner, Button, Card, ErrorView, Field, Loading, Screen, Section, TextField, Title } from '@/components/ui';
import { CheckStatusBadge, MachineStatusBadge } from '@/features/machines/components';
import { useLanguage, useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { formatDateTime } from '@/lib/format';
import { api, queryClient } from '@/state/session';

export default function CheckRecordScreen() {
  const t = useT();
  const lang = useLanguage();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['machines', 'checklist', id], queryFn: () => api.machines.checklist(id) });
  const [note, setNote] = useState('');
  const approve = useMutation({
    mutationFn: () => api.machines.approve(id, note.trim()),
    onSuccess: (rec) => {
      queryClient.setQueryData(['machines', 'checklist', id], rec);
      void queryClient.invalidateQueries({ queryKey: ['machines', 'checklists'] });
      setNote('');
    },
  });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const r = q.data.data;

  return (
    <>
      <Stack.Screen options={{ title: t('machines.checkRecord', { id }) }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching} keyboard>
        <Title sub={r.machine_name}>{r.machine_code}</Title>
        <View style={{ flexDirection: 'row', gap: 8, marginBottom: 12 }}>
          <CheckStatusBadge status={r.checklist_status} />
          <MachineStatusBadge status={r.machine_status} />
        </View>
        <Card>
          <Field label={t('machines.checkedBy')} value={r.user_name} />
          <Field label={t('machines.checkedAt')} value={formatDateTime(r.created_at, lang)} />
          <Field label={t('machines.kind')} value={r.recheck ? t('machines.recheck') : t('machines.general')} />
          {r.recheck && <Field label={t('machines.supervisorApproved')} value={r.date_supervisor_checked ? formatDateTime(r.date_supervisor_checked, lang) : null} />}
          {r.recheck && <Field label={t('machines.managerApproved')} value={r.date_manager_checked ? formatDateTime(r.date_manager_checked, lang) : null} />}
          {!!r.machine_note && <Field label={t('common.note')} value={r.machine_note} />}
          {!!r.reason_not_checked && <Field label={t('machines.approvalNote')} value={r.reason_not_checked} />}
        </Card>

        {r.can_approve && (
          <Section title={t('machines.approve')} style={{ marginTop: 16 }}>
            {approve.isError && <Banner tone="danger" text={errorMessage(approve.error, t)} />}
            <TextField label={t('machines.approvalNoteOptional')} value={note} onChangeText={setNote} maxLength={500} multiline testID="approve-note" />
            <Button title={t('machines.approve')} onPress={() => approve.mutate()} loading={approve.isPending} testID="approve" />
          </Section>
        )}

        {!!r.answers?.length && (
          <Section title={t('machines.answers')} style={{ marginTop: 16 }}>
            {r.answers.map((a, i) => (
              <Card key={i} style={{ marginBottom: 8 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
                  <Text style={{ flex: 1, fontWeight: '600' }}>{a.question}</Text>
                  <Badge label={a.answer ?? '—'} tone={a.answer === 'NG' ? 'danger' : a.answer === 'OK' ? 'success' : 'neutral'} />
                </View>
                {!!a.remark && <Text style={{ color: '#6B7280', marginTop: 4 }}>{a.remark}</Text>}
              </Card>
            ))}
          </Section>
        )}
        {!!r.image_url && <Image source={{ uri: r.image_url }} style={{ height: 220, borderRadius: 12 }} contentFit="cover" cachePolicy="memory" />}
        <Button title={t('machines.openMachine')} variant="ghost" onPress={() => router.push(`/machines/m/${encodeURIComponent(r.machine_code)}`)} style={{ marginTop: 12 }} />
      </Screen>
    </>
  );
}
