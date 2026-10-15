import { router, Stack, useLocalSearchParams } from 'expo-router';

import { InfiniteList } from '@/components/InfiniteList';
import { ListItem } from '@/components/ui';
import { CheckStatusBadge } from '@/features/machines/components';
import { useLanguage, useT } from '@/i18n';
import { formatDateTime } from '@/lib/format';
import { api } from '@/state/session';

/** My checks, or (?pending=1) re-checks waiting for my approval. */
export default function ChecksScreen() {
  const t = useT();
  const lang = useLanguage();
  const pending = useLocalSearchParams<{ pending?: string }>().pending === '1';
  return (
    <>
      <Stack.Screen options={{ title: pending ? t('machines.waiting') : t('machines.myChecks') }} />
      <InfiniteList
        queryKey={['machines', 'checklists', { pending }]}
        fetchPage={(page) => api.machines.checklists({ pending, page })}
        keyOf={(r) => r.id}
        emptyTitle={pending ? t('machines.nothingWaiting') : t('machines.noChecks')}
        renderItem={(r) => (
          <ListItem
            title={`${r.machine_code} · ${r.machine_name}`}
            subtitle={[formatDateTime(r.created_at, lang), r.user_name].filter(Boolean).join(' · ')}
            meta={r.recheck ? t('machines.recheck') : t('machines.general')}
            right={<CheckStatusBadge status={r.checklist_status} />}
            onPress={() => router.push(`/machines/check/${r.id}`)}
          />
        )}
      />
    </>
  );
}
