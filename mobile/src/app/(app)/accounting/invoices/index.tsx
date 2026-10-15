import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { ListItem } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function InvoicesScreen() {
  const t = useT();
  const lang = useLanguage();
  const customerId = Number(useLocalSearchParams<{ customer_id?: string }>().customer_id) || undefined;
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  return (
    <>
      <Stack.Screen options={{ title: t('accounting.invoices') }} />
      <InfiniteList
        queryKey={['accounting', 'invoices', { q, customerId }]}
        fetchPage={(page) => api.accounting.invoices({ q, customer_id: customerId, page })}
        keyOf={(i) => String(i.invoice_number)}
        header={<SearchBox value={text} onChange={setText} placeholder={t('accounting.searchInvoices')} />}
        renderItem={(i) => (
          <ListItem
            title={`${i.invoice_number} · ${i.customer_name ?? i.customer_code ?? ''}`}
            subtitle={`${formatDate(i.invoice_date, lang)} · ${formatMoney(i.amount, i.currency_code, lang)}`}
            meta={i.seller}
            onPress={() => router.push(`/accounting/invoices/${encodeURIComponent(String(i.invoice_number))}`)}
          />
        )}
      />
    </>
  );
}
