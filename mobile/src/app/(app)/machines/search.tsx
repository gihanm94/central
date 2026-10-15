import { useQuery } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { FlatList } from 'react-native';

import { SearchBox } from '@/components/InfiniteList';
import { EmptyState, ErrorView, ListItem, Loading } from '@/components/ui';
import { MachineStatusBadge } from '@/features/machines/components';
import { useT } from '@/i18n';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function MachineSearch() {
  const t = useT();
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const res = useQuery({ queryKey: ['machines', 'find', q], queryFn: () => api.machines.find(q), enabled: q.length >= 2 });

  return (
    <>
      <Stack.Screen options={{ title: t('machines.search') }} />
      <FlatList
        data={q.length >= 2 ? (res.data?.data ?? []) : []}
        keyExtractor={(m) => String(m.id)}
        keyboardShouldPersistTaps="handled"
        ListHeaderComponent={<SearchBox value={text} onChange={setText} placeholder={t('machines.searchPlaceholder')} />}
        renderItem={({ item }) => (
          <ListItem
            title={`${item.machine_code} · ${item.machine_name}`}
            subtitle={[item.brand, item.model, item.serial_number].filter(Boolean).join(' · ')}
            right={<MachineStatusBadge status={item.machine_status} />}
            onPress={() => router.push(`/machines/m/${encodeURIComponent(item.machine_code)}`)}
          />
        )}
        ListEmptyComponent={
          q.length < 2 ? (
            <EmptyState title={t('machines.searchHint')} />
          ) : res.isPending ? (
            <Loading />
          ) : res.isError ? (
            <ErrorView error={res.error} onRetry={() => void res.refetch()} />
          ) : (
            <EmptyState title={t('common.noResults')} />
          )
        }
      />
    </>
  );
}
