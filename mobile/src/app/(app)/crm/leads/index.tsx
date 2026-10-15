import { router, Stack } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { ListItem, Segmented } from '@/components/ui';
import { useT } from '@/i18n';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function LeadsScreen() {
  const t = useT();
  const [text, setText] = useState('');
  const [mine, setMine] = useState<'all' | 'mine'>('all');
  const q = useDebounced(text.trim());
  return (
    <>
      <Stack.Screen options={{ title: t('crm.leads') }} />
      <InfiniteList
        queryKey={['crm', 'leads', { q, mine }]}
        fetchPage={(page) => api.crm.leads({ q, mine: mine === 'mine', page })}
        keyOf={(l) => l.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('crm.searchLeads')} />
            <View style={{ paddingHorizontal: 12 }}>
              <Segmented options={[{ value: 'all', label: t('crm.all') }, { value: 'mine', label: t('crm.mine') }]} value={mine} onChange={setMine} />
            </View>
          </View>
        }
        renderItem={(l) => (
          <ListItem
            title={l.name_en}
            subtitle={[l.name_th, l.code].filter(Boolean).join(' · ')}
            meta={[l.province, l.owner].filter(Boolean).join(' · ')}
            onPress={() => router.push(`/crm/leads/${l.id}`)}
          />
        )}
      />
    </>
  );
}
