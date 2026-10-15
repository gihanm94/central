import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { Badge, ListItem, Segmented } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function OrdersScreen() {
  const t = useT();
  const lang = useLanguage();
  const customerId = Number(useLocalSearchParams<{ customer_id?: string }>().customer_id) || undefined;
  const [credit, setCredit] = useState<'any' | '0' | '1'>('any');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const filters = { q, customer_id: customerId, credit: credit === 'any' ? undefined : credit };
  return (
    <>
      <Stack.Screen options={{ title: t('accounting.orders') }} />
      <InfiniteList
        queryKey={['accounting', 'orders', filters]}
        fetchPage={(page) => api.accounting.orders({ ...filters, page })}
        keyOf={(o) => o.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('accounting.searchOrders')} />
            <View style={{ paddingHorizontal: 12 }}>
              <Segmented options={[{ value: 'any', label: t('crm.all') }, { value: '0', label: t('accounting.normal') }, { value: '1', label: t('accounting.credit') }]} value={credit} onChange={setCredit} />
            </View>
          </View>
        }
        renderItem={(o) => (
          <ListItem
            title={`${o.order_number ?? o.id} · ${o.customer_name ?? o.customer_code ?? ''}`}
            subtitle={[formatDate(o.order_date, lang), o.total != null ? formatMoney(o.total, null, lang) : null].filter(Boolean).join(' · ')}
            meta={o.seller_name}
            right={o.is_credit ? <Badge label={t('accounting.credit')} tone="warn" /> : undefined}
            onPress={() => router.push(`/accounting/orders/${o.id}`)}
          />
        )}
      />
    </>
  );
}
