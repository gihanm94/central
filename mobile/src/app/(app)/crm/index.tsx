import { useQuery } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import type { CrmSearch, SearchHit } from '@/api/types';
import { SearchBox } from '@/components/InfiniteList';
import { Button, ErrorView, ListItem, Loading, Screen, Section, Stat, StatGrid } from '@/components/ui';
import { stageKey } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { formatMoney } from '@/lib/format';
import { can } from '@/lib/permissions';
import { useDebounced } from '@/lib/useDebounced';
import { api, useSession } from '@/state/session';

export default function CrmHome() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const stats = useQuery({ queryKey: ['crm', 'stats'], queryFn: () => api.crm.stats() });
  const search = useQuery({ queryKey: ['crm', 'search', q], queryFn: () => api.crm.search(q), enabled: q.length >= 2 });
  const canLog = can(profile, 'crm', 'crm_activities', 'create');
  const s = stats.data?.data;
  const hits = search.data?.data && !Array.isArray(search.data.data) ? (search.data.data as CrmSearch) : null;

  const hitList = (title: string, list: SearchHit[] | undefined, path: string) =>
    list && list.length > 0 ? (
      <Section title={title}>
        {list.map((h) => (
          <ListItem key={h.id} title={h.label} subtitle={h.sub} onPress={() => router.push(`/crm/${path}/${h.id}`)} />
        ))}
      </Section>
    ) : null;

  return (
    <>
      <Stack.Screen options={{ title: t('crm.title') }} />
      <Screen onRefresh={() => void stats.refetch()} refreshing={stats.isRefetching} keyboard>
        <SearchBox value={text} onChange={setText} placeholder={t('crm.searchPlaceholder')} />
        {q.length >= 2 ? (
          search.isPending ? (
            <Loading />
          ) : search.isError ? (
            <ErrorView error={search.error} />
          ) : (
            <>
              {hitList(t('crm.leads'), hits?.leads, 'leads')}
              {hitList(t('crm.contacts'), hits?.contacts, 'contacts')}
              {hitList(t('crm.opportunities'), hits?.opportunities?.map((h) => ({ ...h, sub: h.sub && h.sub in stageKey ? t(stageKey[h.sub as keyof typeof stageKey]) : h.sub })), 'opportunities')}
              {!hits?.leads?.length && !hits?.contacts?.length && !hits?.opportunities?.length && <ListItem title={t('common.noResults')} />}
            </>
          )
        ) : (
          <>
            {canLog && (
              <View style={{ flexDirection: 'row', gap: 8, marginBottom: 16 }}>
                <Button title={t('crm.logCall')} onPress={() => router.push('/crm/activities/edit?type=CALL')} style={{ flex: 1 }} testID="log-call" />
                <Button title={t('crm.logMeeting')} variant="secondary" onPress={() => router.push('/crm/activities/edit?type=MEETING')} style={{ flex: 1 }} />
              </View>
            )}
            {stats.isError && <ErrorView error={stats.error} onRetry={() => void stats.refetch()} />}
            {s?.activities && (
              <Section title={t('crm.myActivities')}>
                <StatGrid>
                  <Stat label={t('crm.when.overdue')} value={s.activities.overdue} tone={s.activities.overdue ? 'danger' : undefined} onPress={() => router.push('/crm/activities?when=overdue&mine=1')} />
                  <Stat label={t('crm.when.today')} value={s.activities.today} onPress={() => router.push('/crm/activities?when=today&mine=1')} />
                  <Stat label={t('crm.next7')} value={s.activities.next_7_days} onPress={() => router.push('/crm/activities?when=upcoming&mine=1')} />
                  <Stat label={t('crm.doneThisMonth')} value={s.activities.done_this_month} />
                </StatGrid>
              </Section>
            )}
            {s?.my_tasks && (
              <Section title={t('crm.myTasks')}>
                <StatGrid>
                  <Stat label={t('crm.openTasks')} value={s.my_tasks.open} onPress={() => router.push('/crm/tasks?mine=1&open=1')} />
                  <Stat label={t('crm.lateTasks')} value={s.my_tasks.late} tone={s.my_tasks.late ? 'danger' : undefined} onPress={() => router.push('/crm/tasks?mine=1&open=1')} />
                </StatGrid>
              </Section>
            )}
            {!!s?.pipeline?.length && (
              <Section title={t('crm.pipeline')}>
                {s.pipeline.map((p) => (
                  <ListItem
                    key={`${p.stage}-${p.currency}`}
                    title={t(stageKey[p.stage] ?? 'crm.title')}
                    subtitle={t('crm.pipelineRow', { n: p.count, amount: formatMoney(p.amount, p.currency, lang), weighted: formatMoney(p.weighted, p.currency, lang) })}
                    onPress={() => router.push(`/crm/opportunities?stage=${p.stage}`)}
                  />
                ))}
              </Section>
            )}
            {!!s?.won_this_year?.length && (
              <Section title={t('crm.wonThisYear')}>
                {s.won_this_year.map((w) => (
                  <ListItem key={w.currency ?? '-'} title={formatMoney(w.amount, w.currency, lang)} subtitle={t('crm.deals', { n: w.count })} />
                ))}
              </Section>
            )}
            {stats.isPending && <Loading />}
            <Section title={t('crm.browse')}>
              {can(profile, 'crm', 'crm_leads') && <ListItem title={t('crm.leads')} onPress={() => router.push('/crm/leads')} />}
              {can(profile, 'crm', 'crm_contacts') && <ListItem title={t('crm.contacts')} onPress={() => router.push('/crm/contacts')} />}
              {can(profile, 'crm', 'crm_opportunities') && <ListItem title={t('crm.opportunities')} onPress={() => router.push('/crm/opportunities')} />}
              {can(profile, 'crm', 'crm_activities') && <ListItem title={t('crm.activities')} onPress={() => router.push('/crm/activities')} />}
              {can(profile, 'crm', 'crm_tasks') && <ListItem title={t('crm.tasks')} onPress={() => router.push('/crm/tasks')} />}
            </Section>
          </>
        )}
      </Screen>
    </>
  );
}
