import { useMutation, useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { ACTIVITY_STATUSES, type ActivityStatus } from '@/api/types';
import { Badge, Banner, Button, Card, ErrorView, Field, Loading, Screen, Section, Segmented, Title } from '@/components/ui';
import { activityStatusKey, activityStatusTone, activityTypeKey } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { formatDateTime, htmlToText } from '@/lib/format';
import { can } from '@/lib/permissions';
import { api, queryClient, useSession } from '@/state/session';

export default function ActivityScreen() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['crm', 'activity', id], queryFn: () => api.crm.activity(id) });
  const setStatus = useMutation({
    mutationFn: (s: ActivityStatus) => api.crm.setActivityStatus(id, s),
    onSuccess: (res) => {
      queryClient.setQueryData(['crm', 'activity', id], res);
      void queryClient.invalidateQueries({ queryKey: ['crm'], predicate: (x) => x.queryKey[1] !== 'activity' });
    },
  });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const a = q.data.data;
  const canEdit = can(profile, 'crm', 'crm_activities', 'edit');

  return (
    <>
      <Stack.Screen options={{ title: t(activityTypeKey[a.activity_type]) }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={formatDateTime(a.start_at, lang)}>{a.topic}</Title>
        <View style={{ marginBottom: 12 }}>
          <Badge label={t(activityStatusKey[a.status])} tone={activityStatusTone[a.status]} />
        </View>
        {canEdit && (
          <Section title={t('crm.changeStatus')}>
            {setStatus.isError && <Banner tone="danger" text={errorMessage(setStatus.error, t)} />}
            <Segmented
              testIDPrefix="status"
              options={ACTIVITY_STATUSES.map((s) => ({ value: s, label: t(activityStatusKey[s]), tone: activityStatusTone[s] }))}
              value={setStatus.isPending ? setStatus.variables : a.status}
              onChange={(s) => s !== a.status && setStatus.mutate(s)}
            />
          </Section>
        )}
        <Card>
          {a.activity_type === 'CALL' && <Field label={t('crm.direction')} value={a.call_direction ? t(a.call_direction === 'INBOUND' ? 'crm.inbound' : 'crm.outbound') : null} />}
          {a.activity_type === 'CALL' && <Field label={t('crm.minutes')} value={a.call_duration} />}
          {a.activity_type === 'MEETING' && <Field label={t('crm.meetingType')} value={a.meeting_type ? t(`crm.meeting.${a.meeting_type}`) : null} />}
          {a.activity_type === 'MEETING' && <Field label={t('crm.minutes')} value={a.meeting_duration} />}
          {a.activity_type === 'MEETING' && <Field label={t('crm.location')} value={a.meeting_location} />}
          <Field label={t('crm.lead')} value={a.lead_name} onPress={a.lead_id ? () => router.push(`/crm/leads/${a.lead_id}`) : undefined} />
          <Field label={t('crm.contact')} value={a.contact_name} onPress={a.contact_id ? () => router.push(`/crm/contacts/${a.contact_id}`) : undefined} />
          <Field label={t('crm.opportunity')} value={a.opportunity_name} onPress={a.opportunity_id ? () => router.push(`/crm/opportunities/${a.opportunity_id}`) : undefined} />
          <Field label={t('crm.reminder')} value={a.notify_me ? (a.notify_before ? t('crm.minutesBefore', { n: a.notify_before }) : t('crm.atStart')) : null} />
          <Field label={t('crm.owner')} value={a.owner} />
          <Field label={t('crm.description')} value={htmlToText(a.description)} />
        </Card>
        {canEdit && <Button title={t('crm.edit')} variant="secondary" onPress={() => router.push(`/crm/activities/edit?id=${a.id}`)} style={{ marginTop: 16 }} testID="edit-activity" />}
      </Screen>
    </>
  );
}
