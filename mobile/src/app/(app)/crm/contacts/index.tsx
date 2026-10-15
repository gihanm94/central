import { router, Stack } from 'expo-router';
import { useState } from 'react';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { ListItem } from '@/components/ui';
import { useT } from '@/i18n';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function ContactsScreen() {
  const t = useT();
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  return (
    <>
      <Stack.Screen options={{ title: t('crm.contacts') }} />
      <InfiniteList
        queryKey={['crm', 'contacts', { q }]}
        fetchPage={(page) => api.crm.contacts({ q, page })}
        keyOf={(c) => c.id}
        header={<SearchBox value={text} onChange={setText} placeholder={t('crm.searchContacts')} />}
        renderItem={(c) => (
          <ListItem
            title={[c.salutation, c.name_en].filter(Boolean).join(' ')}
            subtitle={[c.job_title, c.lead_name].filter(Boolean).join(' · ')}
            meta={[c.phone, c.email].filter(Boolean).join(' · ')}
            onPress={() => router.push(`/crm/contacts/${c.id}`)}
          />
        )}
      />
    </>
  );
}
