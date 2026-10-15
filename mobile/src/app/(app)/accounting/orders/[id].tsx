import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';

import { Card, ErrorView, Field, ListItem, Loading, Screen, Section, Title } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { api } from '@/state/session';

export default function OrderScreen() {
  const t = useT();
  const lang = useLanguage();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['accounting', 'order', id], queryFn: () => api.accounting.order(id) });
  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const o = q.data.data;
  return (
    <>
      <Stack.Screen options={{ title: t('accounting.orderNo', { n: String(o.order_number ?? o.id) }) }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={o.business_contact_order_number}>{o.customer_name ?? o.customer_code ?? '—'}</Title>
        <Card>
          <Field label={t('accounting.date')} value={formatDate(o.order_date, lang)} />
          <Field label={t('accounting.status')} value={o.status != null ? String(o.status) : null} />
          <Field label={t('accounting.vat')} value={o.vat_number} />
          <Field label={t('accounting.seller')} value={o.seller_name} />
          <Field label={t('accounting.total')} value={formatMoney(o.total, null, lang)} />
          <Field label={t('accounting.customer')} value={o.customer_name} onPress={o.customer_id ? () => router.push(`/accounting/customers/${o.customer_id}`) : undefined} />
        </Card>
        <Section title={t('accounting.rows')} style={{ marginTop: 16 }}>
          {(o.rows ?? []).map((r, i) => (
            <ListItem
              key={i}
              title={[r.part_number, r.description].filter(Boolean).join(' · ') || '—'}
              subtitle={`${formatNumber(r.ordered_quantity, lang, 2)} × ${formatMoney(r.price, null, lang)}${Number(r.discount) ? ` − ${formatNumber(r.discount, lang, 2)} %` : ''}`}
              meta={r.delivery_date ? `${t('accounting.delivery')} ${formatDate(r.delivery_date, lang)}` : null}
            />
          ))}
        </Section>
      </Screen>
    </>
  );
}
