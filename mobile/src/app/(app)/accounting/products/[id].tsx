import { useQuery } from '@tanstack/react-query';
import { Stack, useLocalSearchParams } from 'expo-router';

import { Card, ErrorView, Field, Loading, Screen, Title } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatMoney } from '@/lib/format';
import { api } from '@/state/session';

export default function ProductScreen() {
  const t = useT();
  const lang = useLanguage();
  const id = Number(useLocalSearchParams<{ id: string }>().id);
  const q = useQuery({ queryKey: ['accounting', 'product', id], queryFn: () => api.accounting.product(id) });
  if (q.isPending) return <Loading />;
  if (q.isError) return <ErrorView error={q.error} onRetry={() => void q.refetch()} />;
  const p = q.data.data;
  return (
    <>
      <Stack.Screen options={{ title: p.part_number }} />
      <Screen>
        <Title sub={p.alias}>{p.description ?? p.part_number}</Title>
        <Card>
          <Field label={t('accounting.partNumber')} value={p.part_number} />
          <Field label={t('accounting.standardPrice')} value={p.standard_price != null ? formatMoney(p.standard_price, null, lang) : null} />
        </Card>
      </Screen>
    </>
  );
}
