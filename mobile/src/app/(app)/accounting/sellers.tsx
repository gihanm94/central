import { Stack } from 'expo-router';
import { useState } from 'react';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { ListItem } from '@/components/ui';
import { useT } from '@/i18n';
import { mailtoUrl, openContactLink, telUrl } from '@/lib/contactLinks';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function SellersScreen() {
  const t = useT();
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  return (
    <>
      <Stack.Screen options={{ title: t('accounting.sellers') }} />
      <InfiniteList
        queryKey={['accounting', 'sellers', { q }]}
        fetchPage={(page) => api.accounting.sellers({ q, page })}
        keyOf={(s) => s.id}
        header={<SearchBox value={text} onChange={setText} placeholder={t('accounting.searchSellers')} />}
        renderItem={(s) => {
          const link = s.phone_number ? telUrl(s.phone_number) : s.email ? mailtoUrl(s.email) : null;
          return (
            <ListItem
              title={s.name ?? s.employee_number ?? '—'}
              subtitle={[s.department, s.employee_number].filter(Boolean).join(' · ')}
              meta={[s.phone_number, s.email].filter(Boolean).join(' · ')}
              onPress={link ? () => openContactLink(link) : undefined}
            />
          );
        }}
      />
    </>
  );
}
