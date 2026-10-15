import { router, Stack } from 'expo-router';
import { useState } from 'react';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { ListItem } from '@/components/ui';
import { useT } from '@/i18n';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function CustomersScreen() {
  const t = useT();
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  return (
    <>
      <Stack.Screen options={{ title: t('accounting.customers') }} />
      <InfiniteList
        queryKey={['accounting', 'customers', { q }]}
        fetchPage={(page) => api.accounting.customers({ q, page })}
        keyOf={(c) => c.id}
        header={<SearchBox value={text} onChange={setText} placeholder={t('accounting.searchCustomers')} />}
        renderItem={(c) => (
          <ListItem title={c.name} subtitle={[c.code, c.vat_number].filter(Boolean).join(' · ')} meta={c.seller_name} onPress={() => router.push(`/accounting/customers/${c.id}`)} />
        )}
      />
    </>
  );
}
