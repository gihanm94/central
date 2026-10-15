import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ScrollView, View } from 'react-native';

import type { BillingStatus } from '@/api/types';
import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { Badge, ListItem, Segmented } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

const STATUSES: BillingStatus[] = ['pending', 'overdue', 'paid', 'complete'];

export default function BillingNotesScreen() {
  const t = useT();
  const lang = useLanguage();
  const params = useLocalSearchParams<{ status?: string; customer_id?: string }>();
  const customerId = Number(params.customer_id) || undefined;
  const [status, setStatus] = useState<BillingStatus | 'any'>(STATUSES.includes(params.status as BillingStatus) ? (params.status as BillingStatus) : 'any');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const filters = { q, status: status === 'any' ? undefined : status, customer_id: customerId };

  return (
    <>
      <Stack.Screen options={{ title: t('accounting.billingNotes') }} />
      <InfiniteList
        queryKey={['accounting', 'billing', filters]}
        fetchPage={(page) => api.accounting.billingNotes({ ...filters, page })}
        keyOf={(b) => b.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('accounting.searchBilling')} />
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12 }}>
              <Segmented<BillingStatus | 'any'>
                options={[{ value: 'any', label: t('crm.all') }, ...STATUSES.map((s) => ({ value: s, label: t(`accounting.billing.${s}`), tone: s === 'overdue' ? ('danger' as const) : undefined }))]}
                value={status}
                onChange={setStatus}
              />
            </ScrollView>
          </View>
        }
        renderItem={(b) => (
          <ListItem
            title={`${b.billing_number} · ${b.customer_name ?? b.customer_code ?? ''}`}
            subtitle={`${formatDate(b.billing_at, lang)} · ${formatMoney(b.total_amount, b.currency_code, lang)}`}
            meta={b.remind_date ? `${t('accounting.remind')} ${formatDate(b.remind_date, lang)}` : null}
            right={b.status ? <Badge label={t(`accounting.billing.${b.status}`)} tone={b.status === 'overdue' ? 'danger' : b.status === 'pending' ? 'warn' : 'success'} /> : undefined}
            onPress={() => router.push(`/accounting/billing/${b.id}`)}
          />
        )}
      />
    </>
  );
}
