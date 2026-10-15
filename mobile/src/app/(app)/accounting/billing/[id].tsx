import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';

import { Badge, Card, ErrorView, Field, ListItem, Loading, Screen, Section, Title } from '@/components/ui';
import { PdfButton } from '@/features/accounting/PdfButton';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney } from '@/lib/format';
import { api } from '@/state/session';

export default function BillingNoteScreen() {
  const t = useT();
  const lang = useLanguage();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['accounting', 'billing-note', id], queryFn: () => api.accounting.billingNote(id) });
  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const b = q.data.data;
  const state = b.is_complete ? 'complete' : b.is_paid ? 'paid' : 'pending';

  return (
    <>
      <Stack.Screen options={{ title: b.billing_number }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={formatDate(b.billing_at, lang)}>{b.customer_name ?? b.customer_code ?? '—'}</Title>
        <Badge label={t(`accounting.billing.${state}`)} tone={state === 'pending' ? 'warn' : 'success'} />
        <PdfButton testID="billing-pdf" title={t('accounting.openPdf')} fileName={`${b.billing_number}.pdf`} download={() => api.accounting.billingPdf(b.id)} />
        <Card style={{ marginTop: 16 }}>
          <Field label={t('accounting.total')} value={formatMoney(b.total_amount, b.currency_code, lang)} />
          <Field label={t('accounting.remind')} value={b.remind_date ? formatDate(b.remind_date, lang) : null} />
          <Field label={t('accounting.vat')} value={b.vat_number} />
          <Field label={t('crm.address')} value={b.address} />
          {!!b.note && <Field label={t('common.note')} value={b.note} />}
          {!!b.remark && <Field label={t('accounting.remark')} value={b.remark} />}
          <Field label={t('accounting.customer')} value={b.customer_name} onPress={b.customer_id ? () => router.push(`/accounting/customers/${b.customer_id}`) : undefined} />
        </Card>
        <Section title={t('accounting.invoices')} style={{ marginTop: 16 }}>
          {(b.invoices ?? []).map((i) => (
            <ListItem
              key={i.invoice_number}
              title={i.invoice_number}
              subtitle={`${formatDate(i.invoice_date, lang)} · ${formatMoney(i.amount, b.currency_code, lang)}`}
              meta={i.due_date ? `${t('crm.due')} ${formatDate(i.due_date, lang)}` : null}
              right={i.is_credit ? <Badge label={t('accounting.creditNote')} tone="warn" /> : undefined}
            />
          ))}
        </Section>
      </Screen>
    </>
  );
}
