import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { Badge, Button, Card, ErrorView, Field, Loading, Screen, Title } from '@/components/ui';
import { stageTone } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can } from '@/lib/permissions';
import { api, useSession } from '@/state/session';

export default function OpportunityScreen() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['crm', 'opportunity', id], queryFn: () => api.crm.opportunity(id) });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const o = q.data.data;

  return (
    <>
      <Stack.Screen options={{ title: o.code ?? t('crm.opportunity') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={o.lead_name}>{o.name}</Title>
        <View style={{ marginBottom: 12 }}>
          <Badge label={o.stage_label} tone={stageTone[o.opportunity_stage] ?? 'neutral'} />
        </View>
        <Card>
          <Field label={t('crm.amount')} value={formatMoney(o.amount, o.currency, lang)} />
          <Field label={t('crm.probability')} value={o.probability != null ? `${formatNumber(o.probability, lang)} %` : null} />
          <Field label={t('crm.priority')} value={o.priority} />
          <Field label={t('crm.followUp')} value={o.follow_at ? formatDate(o.follow_at, lang) : null} />
          <Field label={t('crm.closeDate')} value={o.close_at ? formatDate(o.close_at, lang) : null} />
          {!!o.cancel_reason && <Field label={t('crm.reason')} value={o.cancel_reason} />}
          <Field label={t('crm.lead')} value={o.lead_name} onPress={o.lead_id ? () => router.push(`/crm/leads/${o.lead_id}`) : undefined} />
          <Field label={t('crm.contact')} value={o.contact_name} onPress={o.contact_id ? () => router.push(`/crm/contacts/${o.contact_id}`) : undefined} />
          <Field label={t('crm.owner')} value={o.owner} />
        </Card>
        {o.lead_id && can(profile, 'crm', 'crm_activities', 'create') && (
          <Button
            title={t('crm.logCall')}
            variant="secondary"
            style={{ marginTop: 16 }}
            onPress={() =>
              router.push(
                `/crm/activities/edit?type=CALL&lead_id=${o.lead_id}&lead_name=${encodeURIComponent(o.lead_name ?? '')}&opportunity_id=${o.id}&opportunity_name=${encodeURIComponent(o.name)}` +
                  (o.contact_id ? `&contact_id=${o.contact_id}&contact_name=${encodeURIComponent(o.contact_name ?? '')}` : ''),
              )
            }
          />
        )}
      </Screen>
    </>
  );
}
