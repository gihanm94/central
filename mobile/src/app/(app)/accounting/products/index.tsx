import { router, Stack } from 'expo-router';
import { useState } from 'react';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { ListItem } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatMoney } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function ProductsScreen() {
  const t = useT();
  const lang = useLanguage();
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  return (
    <>
      <Stack.Screen options={{ title: t('accounting.products') }} />
      <InfiniteList
        queryKey={['accounting', 'products', { q }]}
        fetchPage={(page) => api.accounting.products({ q, page })}
        keyOf={(p) => p.id}
        header={<SearchBox value={text} onChange={setText} placeholder={t('accounting.searchProducts')} />}
        renderItem={(p) => (
          <ListItem title={p.part_number} subtitle={p.description} meta={p.standard_price != null ? formatMoney(p.standard_price, null, lang) : null} onPress={() => router.push(`/accounting/products/${p.id}`)} />
        )}
      />
    </>
  );
}
