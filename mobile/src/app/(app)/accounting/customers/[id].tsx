import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';

import { Badge, Button, Card, ErrorView, Field, ListItem, Loading, Screen, Section, Title } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney } from '@/lib/format';
import { hasModule } from '@/lib/permissions';
import { api, useSession } from '@/state/session';

/** A customer and its invoices, orders, Inets, billing notes and CRM leads (only the lists the person may see). */
export default function CustomerScreen() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['accounting', 'customer', id], queryFn: () => api.accounting.customer(id) });

  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const { data: c, links } = q.data;

  return (
    <>
      <Stack.Screen options={{ title: c.code ?? t('accounting.customer') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={c.alternative_name}>{c.name}</Title>
        <Card>
          <Field label={t('accounting.vat')} value={c.vat_number} />
          <Field label={t('accounting.corporationId')} value={c.corporation_identification_number} />
          <Field label={t('crm.address')} value={c.address?.trim()} />
          <Field label={t('accounting.seller')} value={c.seller_name} />
          <Field label={t('accounting.creditLimit')} value={c.credit_limit != null ? formatMoney(c.credit_limit, null, lang) : null} />
        </Card>

        {links.invoices && (
          <Section title={t('accounting.invoices')} style={{ marginTop: 16 }}>
            {links.invoices.map((i) => (
              <ListItem key={String(i.invoice_number)} title={String(i.invoice_number)} subtitle={`${formatDate(i.invoice_date, lang)} · ${formatMoney(i.amount, i.currency_code, lang)}`} onPress={() => router.push(`/accounting/invoices/${encodeURIComponent(String(i.invoice_number))}`)} />
            ))}
            <Button title={t('accounting.allInvoices')} variant="ghost" small onPress={() => router.push(`/accounting/invoices?customer_id=${c.id}`)} />
          </Section>
        )}
        {links.orders && (
          <Section title={t('accounting.orders')}>
            {links.orders.map((o) => (
              <ListItem key={o.id} title={String(o.order_number ?? o.id)} subtitle={[formatDate(o.order_date, lang), o.business_contact_order_number].filter(Boolean).join(' · ')} right={o.is_credit ? <Badge label={t('accounting.credit')} tone="warn" /> : undefined} onPress={() => router.push(`/accounting/orders/${o.id}`)} />
            ))}
            <Button title={t('accounting.allOrders')} variant="ghost" small onPress={() => router.push(`/accounting/orders?customer_id=${c.id}`)} />
          </Section>
        )}
        {links.inets && (
          <Section title={t('accounting.inets')}>
            {links.inets.map((n) => (
              <ListItem key={n.id} title={String(n.no_invoice)} subtitle={`${formatDate(n.invoice_date, lang)} · ${formatMoney(n.total_amount, n.currency_code, lang)}`} right={n.is_credit ? <Badge label={t('accounting.creditNote')} tone="warn" /> : undefined} onPress={() => router.push(`/accounting/inets/${n.id}`)} />
            ))}
            <Button title={t('accounting.allInets')} variant="ghost" small onPress={() => router.push(`/accounting/inets?customer_id=${c.id}`)} />
          </Section>
        )}
        {links.billing_notes && (
          <Section title={t('accounting.billingNotes')}>
            {links.billing_notes.map((b) => (
              <ListItem key={b.id} title={b.billing_number} subtitle={`${formatDate(b.billing_at, lang)} · ${formatMoney(b.total_amount, b.currency_code, lang)}`} right={<Badge label={b.is_complete ? t('accounting.billing.complete') : b.is_paid ? t('accounting.billing.paid') : t('accounting.billing.pending')} tone={b.is_complete || b.is_paid ? 'success' : 'warn'} />} onPress={() => router.push(`/accounting/billing/${b.id}`)} />
            ))}
            <Button title={t('accounting.allBillingNotes')} variant="ghost" small onPress={() => router.push(`/accounting/billing?customer_id=${c.id}`)} />
          </Section>
        )}
        {!!links.crm_leads?.length && (
          <Section title={t('accounting.crmLeads')}>
            {links.crm_leads.map((l) => (
              <ListItem key={l.id} title={l.name_en} subtitle={l.code} onPress={hasModule(profile, 'crm') ? () => router.push(`/crm/leads/${l.id}`) : undefined} />
            ))}
          </Section>
        )}
      </Screen>
    </>
  );
}
