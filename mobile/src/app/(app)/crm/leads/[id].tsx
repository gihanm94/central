import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';

import { Badge, Button, Card, ErrorView, Field, ListItem, Loading, Screen, Section, Title } from '@/components/ui';
import { activityStatusKey, activityStatusTone, activityTypeKey, stageTone } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { openContactLink, telUrl } from '@/lib/contactLinks';
import { formatDateTime, formatMoney } from '@/lib/format';
import { can } from '@/lib/permissions';
import { api, useSession } from '@/state/session';

export default function LeadScreen() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['crm', 'lead', id], queryFn: () => api.crm.lead(id) });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const { data: l, contacts, opportunities, activities } = q.data;
  const leadParam = `lead_id=${l.id}&lead_name=${encodeURIComponent(l.name_en)}`;

  return (
    <>
      <Stack.Screen options={{ title: l.code ?? t('crm.lead') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={l.name_th}>{l.name_en}</Title>
        {can(profile, 'crm', 'crm_activities', 'create') && (
          <Button title={t('crm.logCall')} onPress={() => router.push(`/crm/activities/edit?type=CALL&${leadParam}`)} style={{ marginBottom: 16 }} testID="lead-log-call" />
        )}
        <Card>
          <Field label={t('crm.taxId')} value={l.tax_id} />
          <Field label={t('crm.phone')} value={l.phone} onPress={l.phone ? () => openContactLink(telUrl(l.phone!)) : undefined} />
          <Field label={t('crm.mobile')} value={l.mobile} onPress={l.mobile ? () => openContactLink(telUrl(l.mobile!)) : undefined} />
          <Field label={t('crm.address')} value={[l.address, l.city, l.province, l.zipcode, l.country].filter(Boolean).join(', ')} />
          <Field label={t('crm.owner')} value={l.owner} />
          <Field label={t('crm.department')} value={l.department} />
        </Card>

        {contacts && (
          <Section title={`${t('crm.contacts')} (${contacts.length})`} style={{ marginTop: 16 }}>
            {contacts.map((c) => (
              <ListItem key={c.id} title={[c.salutation, c.name_en].filter(Boolean).join(' ')} subtitle={[c.job_title, c.phone, c.email].filter(Boolean).join(' · ')} onPress={() => router.push(`/crm/contacts/${c.id}`)} />
            ))}
          </Section>
        )}
        {opportunities && (
          <Section title={`${t('crm.opportunities')} (${opportunities.length})`}>
            {opportunities.map((o) => (
              <ListItem
                key={o.id}
                title={o.name}
                subtitle={formatMoney(o.amount, o.currency, lang)}
                right={<Badge label={o.stage_label} tone={stageTone[o.opportunity_stage] ?? 'neutral'} />}
                onPress={() => router.push(`/crm/opportunities/${o.id}`)}
              />
            ))}
          </Section>
        )}
        {activities && (
          <Section title={t('crm.latestActivities')}>
            {activities.map((a) => (
              <ListItem
                key={a.id}
                title={a.topic}
                subtitle={[t(activityTypeKey[a.activity_type]), formatDateTime(a.start_at, lang), a.contact_name].filter(Boolean).join(' · ')}
                right={<Badge label={t(activityStatusKey[a.status])} tone={activityStatusTone[a.status]} />}
                onPress={() => router.push(`/crm/activities/${a.id}`)}
              />
            ))}
            <Button title={t('crm.allActivities')} variant="ghost" onPress={() => router.push(`/crm/activities?lead_id=${l.id}`)} />
          </Section>
        )}
      </Screen>
    </>
  );
}
