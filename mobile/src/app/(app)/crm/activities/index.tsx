import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ScrollView, View } from 'react-native';

import { ACTIVITY_STATUSES, ACTIVITY_TYPES, type ActivityStatus, type ActivityType } from '@/api/types';
import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { Badge, Button, ListItem, Segmented } from '@/components/ui';
import { activityStatusKey, activityStatusTone, activityTypeKey } from '@/features/crm/labels';
import { whenFilter, type When } from '@/features/crm/when';
import { useLanguage, useT } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { can } from '@/lib/permissions';
import { useDebounced } from '@/lib/useDebounced';
import { api, useSession } from '@/state/session';

export default function ActivitiesScreen() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const params = useLocalSearchParams<{ when?: string; mine?: string; lead_id?: string }>();
  const [when, setWhen] = useState<When>(['today', 'overdue', 'upcoming'].includes(params.when ?? '') ? (params.when as When) : 'all');
  const [status, setStatus] = useState<ActivityStatus | 'ANY'>('ANY');
  const [type, setType] = useState<ActivityType | 'ANY'>('ANY');
  const [mine, setMine] = useState<'all' | 'mine'>(params.mine === '1' ? 'mine' : 'all');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const leadId = Number(params.lead_id) || undefined;
  const w = whenFilter(when);
  const filters = {
    q,
    from: w.from,
    to: w.to,
    status: status !== 'ANY' ? status : w.status,
    type: type !== 'ANY' ? type : undefined,
    mine: mine === 'mine',
    lead_id: leadId,
  };

  return (
    <>
      <Stack.Screen options={{ title: t('crm.activities') }} />
      <InfiniteList
        queryKey={['crm', 'activities', filters]}
        fetchPage={(page) => api.crm.activities({ ...filters, page })}
        keyOf={(a) => a.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('crm.searchActivities')} />
            <View style={{ paddingHorizontal: 12 }}>
              {can(profile, 'crm', 'crm_activities', 'create') && (
                <Button title={t('crm.logCall')} small onPress={() => router.push(`/crm/activities/edit?type=CALL${leadId ? `&lead_id=${leadId}` : ''}`)} style={{ marginBottom: 12 }} />
              )}
              <Segmented<When>
                options={[
                  { value: 'all', label: t('crm.all') },
                  { value: 'today', label: t('crm.when.today') },
                  { value: 'overdue', label: t('crm.when.overdue'), tone: 'danger' },
                  { value: 'upcoming', label: t('crm.when.upcoming') },
                ]}
                value={when}
                onChange={setWhen}
              />
            </View>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12 }}>
              <Segmented<ActivityType | 'ANY'>
                options={[{ value: 'ANY', label: t('crm.anyType') }, ...ACTIVITY_TYPES.map((x) => ({ value: x, label: t(activityTypeKey[x]) }))]}
                value={type}
                onChange={setType}
              />
            </ScrollView>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12 }}>
              <Segmented<ActivityStatus | 'ANY'>
                options={[{ value: 'ANY', label: t('crm.anyStatus') }, ...ACTIVITY_STATUSES.map((x) => ({ value: x, label: t(activityStatusKey[x]), tone: activityStatusTone[x] }))]}
                value={status}
                onChange={setStatus}
              />
            </ScrollView>
            <View style={{ paddingHorizontal: 12 }}>
              <Segmented options={[{ value: 'all', label: t('crm.everyone') }, { value: 'mine', label: t('crm.mine') }]} value={mine} onChange={setMine} />
            </View>
          </View>
        }
        renderItem={(a) => (
          <ListItem
            title={a.topic}
            subtitle={[t(activityTypeKey[a.activity_type]), formatDateTime(a.start_at, lang)].join(' · ')}
            meta={[a.lead_name, a.contact_name].filter(Boolean).join(' · ')}
            right={<Badge label={t(activityStatusKey[a.status])} tone={activityStatusTone[a.status]} />}
            onPress={() => router.push(`/crm/activities/${a.id}`)}
          />
        )}
      />
    </>
  );
}
