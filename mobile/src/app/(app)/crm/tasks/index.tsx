import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ScrollView, View } from 'react-native';

import { TASK_STATUSES, type TaskStatus } from '@/api/types';
import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { Badge, ListItem, Segmented } from '@/components/ui';
import { taskStatusKey, taskStatusTone } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { formatDate, parseDate } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function TasksScreen() {
  const t = useT();
  const lang = useLanguage();
  const params = useLocalSearchParams<{ mine?: string; open?: string }>();
  const [mine, setMine] = useState<'all' | 'mine'>(params.mine === '0' ? 'all' : 'mine');
  const [status, setStatus] = useState<TaskStatus | 'OPEN' | 'ANY'>(params.open === '0' ? 'ANY' : 'OPEN');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const filters = { q, mine: mine === 'mine', open: status === 'OPEN', status: status !== 'OPEN' && status !== 'ANY' ? status : undefined };

  return (
    <>
      <Stack.Screen options={{ title: t('crm.tasks') }} />
      <InfiniteList
        queryKey={['crm', 'tasks', filters]}
        fetchPage={(page) => api.crm.tasks({ ...filters, page })}
        keyOf={(x) => x.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('crm.searchTasks')} />
            <View style={{ paddingHorizontal: 12 }}>
              <Segmented options={[{ value: 'mine', label: t('crm.assignedToMe') }, { value: 'all', label: t('crm.everyone') }]} value={mine} onChange={setMine} />
            </View>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12 }}>
              <Segmented<TaskStatus | 'OPEN' | 'ANY'>
                options={[
                  { value: 'OPEN', label: t('crm.openOnly') },
                  { value: 'ANY', label: t('crm.anyStatus') },
                  ...TASK_STATUSES.map((s) => ({ value: s, label: t(taskStatusKey[s]), tone: taskStatusTone[s] })),
                ]}
                value={status}
                onChange={setStatus}
              />
            </ScrollView>
          </View>
        }
        renderItem={(x) => {
          const end = parseDate(x.end_date);
          const late = !!end && end.getTime() < Date.now() - 86_400_000 && x.status !== 'DONE' && x.status !== 'CANCELLED';
          return (
            <ListItem
              title={x.name}
              subtitle={[x.project_name, x.end_date ? `${t('crm.due')} ${formatDate(x.end_date, lang)}` : null].filter(Boolean).join(' · ')}
              meta={x.assignees.map((a) => a.name).filter(Boolean).join(', ')}
              right={<Badge label={late ? t('crm.late') : t(taskStatusKey[x.status])} tone={late ? 'danger' : taskStatusTone[x.status]} />}
              onPress={() => router.push(`/crm/tasks/${x.id}`)}
            />
          );
        }}
      />
    </>
  );
}
