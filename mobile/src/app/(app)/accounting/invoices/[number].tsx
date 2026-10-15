import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';

import { Card, ErrorView, Field, ListItem, Loading, Screen, Section, Title } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { api } from '@/state/session';

export default function InvoiceScreen() {
  const t = useT();
  const lang = useLanguage();
  const number = useLocalSearchParams<{ number: string }>().number;
  const q = useQuery({ queryKey: ['accounting', 'invoice', number], queryFn: () => api.accounting.invoice(number) });
  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const i = q.data.data;
  return (
    <>
      <Stack.Screen options={{ title: String(i.invoice_number) }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={formatDate(i.invoice_date, lang)}>{i.customer_name ?? i.customer_code ?? '—'}</Title>
        <Card>
          <Field label={t('accounting.amount')} value={formatMoney(i.amount, i.currency_code, lang)} />
          <Field label={t('accounting.customerOrder')} value={i.customer_order_number} />
          <Field label={t('accounting.seller')} value={i.seller} />
          <Field label={t('accounting.customer')} value={i.customer_name ?? i.customer_code} onPress={i.customer_id ? () => router.push(`/accounting/customers/${i.customer_id}`) : undefined} />
        </Card>
        <Section title={t('accounting.lines')} style={{ marginTop: 16 }}>
          {(i.lines ?? []).map((l, k) => (
            <ListItem key={k} title={l.part_number ?? '—'} subtitle={`${formatNumber(l.invoiced_quantity, lang, 2)} ${l.unit_code ?? ''} × ${formatMoney(l.price, null, lang)}${Number(l.discount) ? ` − ${formatNumber(l.discount, lang, 2)} %` : ''}`} />
          ))}
        </Section>
      </Screen>
    </>
  );
}
