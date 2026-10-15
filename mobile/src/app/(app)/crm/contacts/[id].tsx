import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { View } from 'react-native';

import { Button, Card, ErrorView, Field, Loading, Screen, Section, Title } from '@/components/ui';
import { useT } from '@/i18n';
import { mailtoUrl, openContactLink, telUrl } from '@/lib/contactLinks';
import { can } from '@/lib/permissions';
import { api, useSession } from '@/state/session';

export default function ContactScreen() {
  const t = useT();
  const { profile } = useSession();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['crm', 'contact', id], queryFn: () => api.crm.contact(id) });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const c = q.data.data;
  const phone = c.phone ? telUrl(c.phone, c.phone_ext) : null;
  const mail = c.email ? mailtoUrl(c.email) : null;

  return (
    <>
      <Stack.Screen options={{ title: c.code ?? t('crm.contact') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={[c.job_title, c.department].filter(Boolean).join(' · ')}>{[c.salutation, c.name_en].filter(Boolean).join(' ')}</Title>
        <View style={{ flexDirection: 'row', gap: 8, marginBottom: 16 }}>
          {phone && <Button title={t('crm.call')} onPress={() => openContactLink(phone)} style={{ flex: 1 }} />}
          {mail && <Button title={t('crm.email')} variant="secondary" onPress={() => openContactLink(mail)} style={{ flex: 1 }} />}
        </View>
        <Card>
          {!!c.name_th && <Field label={t('crm.nameTh')} value={c.name_th} />}
          <Field label={t('crm.phone')} value={c.phone ? `${c.phone}${c.phone_ext ? ` ext. ${c.phone_ext}` : ''}` : null} onPress={phone ? () => openContactLink(phone) : undefined} />
          <Field label={t('crm.email')} value={c.email} onPress={mail ? () => openContactLink(mail) : undefined} />
          {c.mobiles.map((m, i) => (
            <Field
              key={i}
              label={`${t('crm.mobile')}${m.label ? ` · ${m.label}` : ''}${m.is_primary ? ` · ${t('crm.main')}` : ''}`}
              value={m.number}
              onPress={() => openContactLink(telUrl(m.number, m.ext))}
            />
          ))}
          <Field label={t('crm.lead')} value={c.lead_name} onPress={c.lead_id ? () => router.push(`/crm/leads/${c.lead_id}`) : undefined} />
          <Field label={t('crm.owner')} value={c.owner} />
        </Card>
        {c.lead_id && can(profile, 'crm', 'crm_activities', 'create') && (
          <Section style={{ marginTop: 16 }}>
            <Button
              title={t('crm.logCall')}
              variant="secondary"
              onPress={() => router.push(`/crm/activities/edit?type=CALL&lead_id=${c.lead_id}&lead_name=${encodeURIComponent(c.lead_name ?? '')}&contact_id=${c.id}&contact_name=${encodeURIComponent(c.name_en)}`)}
            />
          </Section>
        )}
      </Screen>
    </>
  );
}
