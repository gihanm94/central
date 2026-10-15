import { useMutation, useQuery } from '@tanstack/react-query';
import { Stack, useLocalSearchParams } from 'expo-router';

import { TASK_STATUSES, type TaskStatus } from '@/api/types';
import { Banner, Card, ErrorView, Field, Loading, Screen, Section, Segmented, Title } from '@/components/ui';
import { taskStatusKey, taskStatusTone } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { formatDate, htmlToText } from '@/lib/format';
import { can } from '@/lib/permissions';
import { api, queryClient, useSession } from '@/state/session';

export default function TaskScreen() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['crm', 'task', id], queryFn: () => api.crm.task(id) });
  const setStatus = useMutation({
    mutationFn: (s: TaskStatus) => api.crm.setTaskStatus(id, s),
    onSuccess: (res) => {
      queryClient.setQueryData(['crm', 'task', id], res);
      void queryClient.invalidateQueries({ queryKey: ['crm'], predicate: (x) => x.queryKey[1] !== 'task' });
    },
  });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const x = q.data.data;

  return (
    <>
      <Stack.Screen options={{ title: x.code ?? t('crm.task') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={x.project_name}>{x.name}</Title>
        <Section title={t('crm.status')}>
          {setStatus.isError && <Banner tone="danger" text={errorMessage(setStatus.error, t)} />}
          <Segmented
            testIDPrefix="task-status"
            options={TASK_STATUSES.map((s) => ({ value: s, label: t(taskStatusKey[s]), tone: taskStatusTone[s] }))}
            value={setStatus.isPending ? setStatus.variables : x.status}
            onChange={(s) => {
              if (s !== x.status && can(profile, 'crm', 'crm_tasks', 'edit')) setStatus.mutate(s);
            }}
          />
        </Section>
        <Card>
          <Field label={t('crm.start')} value={x.start_date ? formatDate(x.start_date, lang) : null} />
          <Field label={t('crm.due')} value={x.end_date ? formatDate(x.end_date, lang) : null} />
          <Field label={t('crm.assignees')} value={x.assignees.map((a) => a.name).filter(Boolean).join(', ')} />
          <Field label={t('crm.owner')} value={x.owner} />
          <Field label={t('crm.description')} value={htmlToText(x.description)} />
        </Card>
      </Screen>
    </>
  );
}
