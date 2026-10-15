import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { Badge, ListItem, Segmented } from '@/components/ui';
import { inetState } from '@/features/accounting/inetState';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

export default function InetsScreen() {
  const t = useT();
  const lang = useLanguage();
  const customerId = Number(useLocalSearchParams<{ customer_id?: string }>().customer_id) || undefined;
  const [kind, setKind] = useState<'invoice' | 'credit'>('invoice');
  const [send, setSend] = useState<'any' | 'sent' | 'unsent'>('any');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const filters = { q, credit: kind === 'credit', send: send === 'any' ? undefined : send, customer_id: customerId };

  return (
    <>
      <Stack.Screen options={{ title: t('accounting.inets') }} />
      <InfiniteList
        queryKey={['accounting', 'inets', filters]}
        fetchPage={(page) => api.accounting.inets({ ...filters, page })}
        keyOf={(n) => n.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('accounting.searchInets')} />
            <View style={{ paddingHorizontal: 12 }}>
              <Segmented options={[{ value: 'invoice', label: t('accounting.taxInvoices') }, { value: 'credit', label: t('accounting.creditNotes') }]} value={kind} onChange={setKind} />
              <Segmented options={[{ value: 'any', label: t('crm.all') }, { value: 'sent', label: t('accounting.sent') }, { value: 'unsent', label: t('accounting.notSent') }]} value={send} onChange={setSend} />
            </View>
          </View>
        }
        renderItem={(n) => {
          const s = inetState(n);
          return (
            <ListItem
              title={`${n.no_invoice} · ${n.customer_name ?? n.customer_code ?? ''}`}
              subtitle={`${formatDate(n.invoice_date, lang)} · ${formatMoney(n.total_amount, n.currency_code, lang)}`}
              right={<Badge label={t(`accounting.inetState.${s}`)} tone={s === 'signed' ? 'success' : s === 'sent' ? 'info' : s === 'generated' ? 'neutral' : 'warn'} />}
              onPress={() => router.push(`/accounting/inets/${n.id}`)}
            />
          );
        }}
      />
    </>
  );
}
