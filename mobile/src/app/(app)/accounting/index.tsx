import { useQuery } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { Text } from 'react-native';

import { isApiError } from '@/api/errors';
import type { AccountingStats } from '@/api/types';
import { Banner, ErrorView, ListItem, Loading, Screen, Section, Stat, StatGrid } from '@/components/ui';
import { useLanguage, useT, type Language, type TKey } from '@/i18n';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { can } from '@/lib/permissions';
import { api, useSession } from '@/state/session';

const LINKS: { resource: string; title: TKey; href: string }[] = [
  { resource: 'accounting_customers', title: 'accounting.customers', href: '/accounting/customers' },
  { resource: 'accounting_invoices', title: 'accounting.invoices', href: '/accounting/invoices' },
  { resource: 'accounting_orders', title: 'accounting.orders', href: '/accounting/orders' },
  { resource: 'accounting_inet', title: 'accounting.inets', href: '/accounting/inets' },
  { resource: 'accounting_billing', title: 'accounting.billingNotes', href: '/accounting/billing' },
  { resource: 'accounting_products', title: 'accounting.products', href: '/accounting/products' },
  { resource: 'accounting_sellers', title: 'accounting.sellers', href: '/accounting/sellers' },
];

export default function AccountingHome() {
  const t = useT();
  const lang = useLanguage();
  const { profile } = useSession();
  const q = useQuery({ queryKey: ['accounting', 'stats'], queryFn: () => api.accounting.stats() });
  const forbidden = isApiError(q.error) && q.error.status === 403;

  return (
    <>
      <Stack.Screen options={{ title: t('accounting.title') }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Banner tone="neutral" text={t('accounting.readOnly')} />
        {q.isPending ? <Loading /> : q.isError ? !forbidden && <ErrorView error={q.error} onRetry={() => void q.refetch()} /> : <Overview s={q.data.data} lang={lang} />}
        <Section title={t('crm.browse')}>
          {LINKS.filter((l) => can(profile, 'accounting', l.resource)).map((l) => (
            <ListItem key={l.href} title={t(l.title)} onPress={() => router.push(l.href as never)} />
          ))}
        </Section>
      </Screen>
    </>
  );
}

/** Only the blocks the server returned (the person's permissions decide). */
function Overview({ s, lang }: { s: AccountingStats; lang: Language }) {
  const t = useT();
  const money = (v: number | string | null | undefined) => formatMoney(v ?? null, 'THB', lang);
  const inet = s.inet?.inet && !Array.isArray(s.inet.inet) ? s.inet.inet : null;
  return (
    <>
      {s.finance?.kpi && (
        <Section title={t('accounting.revenue')}>
          <StatGrid>
            <Stat label={t('accounting.thisMonth')} value={money(s.finance.kpi.month)} />
            <Stat label={t('accounting.sameMonthLastYear')} value={money(s.finance.kpi.month_prev_year)} />
            <Stat label={t('accounting.ytd')} value={money(s.finance.kpi.ytd)} />
            <Stat label={t('accounting.ytdLastYear')} value={money(s.finance.kpi.ytd_prev)} />
            <Stat label={t('accounting.invoicesThisMonth')} value={formatNumber(s.finance.kpi.invoices_month, lang)} />
            {s.finance.receivable !== undefined && <Stat label={t('accounting.receivable')} value={money(s.finance.receivable)} tone="warn" />}
          </StatGrid>
        </Section>
      )}
      {!!s.finance?.aging?.length && (
        <Section title={t('accounting.aging')}>
          {s.finance.aging.map((a) => (
            <ListItem key={a.b} title={agingLabel(a.b, t)} right={<Text>{money(a.v)}</Text>} />
          ))}
        </Section>
      )}
      {!!s.finance?.customers?.length && (
        <Section title={t('accounting.topCustomers')}>
          {s.finance.customers.slice(0, 5).map((c) => (
            <ListItem key={c.name} title={c.name} right={<Text>{money(c.v)}</Text>} />
          ))}
        </Section>
      )}
      {s.orders && (
        <Section title={t('accounting.orders')}>
          <StatGrid>
            <Stat label={t('accounting.ordersThisMonth')} value={formatNumber(s.orders.orders_this_month ?? 0, lang)} onPress={() => router.push('/accounting/orders')} />
          </StatGrid>
        </Section>
      )}
      {s.inet && (
        <Section title={t('accounting.inets')}>
          <StatGrid>
            {inet && <Stat label={t('accounting.inetWaiting')} value={inet.waiting} />}
            {inet && <Stat label={t('accounting.inetProcessing')} value={inet.processing} />}
            {inet && <Stat label={t('accounting.inetSigned')} value={inet.signed} tone="success" />}
            {s.inet.not_in_inet !== undefined && <Stat label={t('accounting.notInInet')} value={s.inet.not_in_inet} tone={s.inet.not_in_inet ? 'warn' : undefined} />}
          </StatGrid>
        </Section>
      )}
      {s.billing && (
        <Section title={t('accounting.billingNotes')}>
          <StatGrid>
            <Stat label={t('accounting.billingToday')} value={s.billing.billing_today ?? 0} onPress={() => router.push('/accounting/billing')} />
            <Stat label={t('accounting.billingOpen')} value={money(s.billing.billing_open)} />
            {(s.billing.billing_status ?? []).map((b) => (
              <Stat key={b.s} label={t(`accounting.billing.${b.s}`)} value={b.n} tone={b.s === 'overdue' ? 'danger' : undefined} onPress={() => router.push(`/accounting/billing?status=${b.s}`)} />
            ))}
          </StatGrid>
        </Section>
      )}
      {!!s.built && <Text style={{ color: '#9CA3AF', fontSize: 12, marginBottom: 12 }}>{t('accounting.updated', { at: formatDateTime(s.built, lang) })}</Text>}
    </>
  );
}

function agingLabel(bucket: string, t: (k: TKey) => string): string {
  const key = ({ '0': 'accounting.aging.notDue', '1': 'accounting.aging.1_30', '2': 'accounting.aging.31_60', '3': 'accounting.aging.61_90', '4': 'accounting.aging.90plus' } as Record<string, TKey>)[bucket.charAt(0)];
  return key ? t(key) : bucket;
}
