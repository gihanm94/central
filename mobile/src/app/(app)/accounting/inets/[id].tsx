import { useQuery } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { Text, View } from 'react-native';

import { Badge, Card, ErrorView, Field, Loading, Screen, Section, Title } from '@/components/ui';
import { PdfButton } from '@/features/accounting/PdfButton';
import { useLanguage, useT } from '@/i18n';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { api } from '@/state/session';

export default function InetScreen() {
  const t = useT();
  const lang = useLanguage();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['accounting', 'inet', id], queryFn: () => api.accounting.inet(id) });
  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const n = q.data.data;

  return (
    <>
      <Stack.Screen options={{ title: String(n.no_invoice) }} />
      <Screen onRefresh={() => void q.refetch()} refreshing={q.isRefetching}>
        <Title sub={formatDate(n.invoice_date, lang)}>{n.customer_name ?? n.customer_code ?? '—'}</Title>
        <Section title={t('accounting.documents')}>
          {n.documents.map((d) => (
            <Card key={d.type} style={{ marginBottom: 8 }}>
              <Text style={{ fontSize: 16, fontWeight: '700', marginBottom: 6 }}>{d.label}</Text>
              <View style={{ flexDirection: 'row', gap: 6, flexWrap: 'wrap' }}>
                <Badge label={d.generated ? t('accounting.generated') : t('accounting.notGenerated')} tone={d.generated ? 'success' : 'warn'} />
                <Badge label={d.sent ? t('accounting.sent') : t('accounting.notSent')} tone={d.sent ? 'success' : 'neutral'} />
                <Badge label={d.signed ? t('accounting.signed') : t('accounting.notSigned')} tone={d.signed ? 'success' : 'neutral'} />
              </View>
              {!!d.message && <Text style={{ color: '#6B7280', marginTop: 6 }}>{d.message}</Text>}
              {/* Always offered: a missing PDF answers 404 pdf_not_available with the server's message. */}
              <PdfButton testID={`pdf-${d.type}`} title={t('accounting.openPdf')} fileName={`${n.no_invoice}_${d.type}.pdf`} download={() => api.accounting.inetPdf(n.id, d.type)} />
              {d.signed && <PdfButton testID={`pdf-${d.type}-signed`} title={t('accounting.openSignedPdf')} fileName={`${n.no_invoice}_${d.type}_signed.pdf`} download={() => api.accounting.inetPdf(n.id, d.type, true)} />}
            </Card>
          ))}
          <Text style={{ color: '#9CA3AF', fontSize: 12 }}>{t('accounting.noGenerateHere')}</Text>
        </Section>
        <Card>
          <Field label={t('accounting.amount')} value={formatMoney(n.amount, n.currency_code, lang)} />
          <Field label={t('accounting.tax')} value={formatMoney(n.tax_amount, n.currency_code, lang)} />
          <Field label={t('accounting.total')} value={formatMoney(n.total_amount, n.currency_code, lang)} />
          {n.exchange_rate != null && <Field label={t('accounting.exchangeRate')} value={formatNumber(n.exchange_rate, lang, 4)} />}
          <Field label={t('accounting.vat')} value={[n.tax_id, n.branch].filter(Boolean).join(' / ')} />
          <Field label={t('crm.email')} value={n.email} />
          <Field label={t('accounting.customerOrder')} value={n.customer_order_number} />
          <Field label={t('accounting.seller')} value={n.seller_name} />
          {!!n.remark && <Field label={t('common.note')} value={n.remark} />}
          <Field label={t('accounting.customer')} value={n.customer_name} onPress={n.customer_id ? () => router.push(`/accounting/customers/${n.customer_id}`) : undefined} />
        </Card>
      </Screen>
    </>
  );
}
