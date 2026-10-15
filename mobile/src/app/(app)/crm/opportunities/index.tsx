import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { ScrollView, View } from 'react-native';

import { OPPORTUNITY_STAGES, type OpportunityStage } from '@/api/types';
import { InfiniteList, SearchBox } from '@/components/InfiniteList';
import { Badge, ListItem, Segmented } from '@/components/ui';
import { stageKey, stageTone } from '@/features/crm/labels';
import { useLanguage, useT } from '@/i18n';
import { formatMoney } from '@/lib/format';
import { useDebounced } from '@/lib/useDebounced';
import { api } from '@/state/session';

type StageFilter = OpportunityStage | 'ALL' | 'OPEN';

export default function OpportunitiesScreen() {
  const t = useT();
  const lang = useLanguage();
  const params = useLocalSearchParams<{ stage?: string }>();
  const initial = (OPPORTUNITY_STAGES as readonly string[]).includes(params.stage ?? '') ? (params.stage as OpportunityStage) : 'OPEN';
  const [stage, setStage] = useState<StageFilter>(initial);
  const [mine, setMine] = useState<'all' | 'mine'>('all');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const filters = { q, stage: stage === 'ALL' || stage === 'OPEN' ? undefined : stage, open: stage === 'OPEN', mine: mine === 'mine' };

  return (
    <>
      <Stack.Screen options={{ title: t('crm.opportunities') }} />
      <InfiniteList
        queryKey={['crm', 'opportunities', filters]}
        fetchPage={(page) => api.crm.opportunities({ ...filters, page })}
        keyOf={(o) => o.id}
        header={
          <View>
            <SearchBox value={text} onChange={setText} placeholder={t('crm.searchOpportunities')} />
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ paddingHorizontal: 12 }}>
              <Segmented<StageFilter>
                options={[
                  { value: 'OPEN', label: t('crm.openOnly') },
                  { value: 'ALL', label: t('crm.all') },
                  ...OPPORTUNITY_STAGES.map((s) => ({ value: s, label: t(stageKey[s]), tone: stageTone[s] })),
                ]}
                value={stage}
                onChange={setStage}
              />
            </ScrollView>
            <View style={{ paddingHorizontal: 12 }}>
              <Segmented options={[{ value: 'all', label: t('crm.everyone') }, { value: 'mine', label: t('crm.mine') }]} value={mine} onChange={setMine} />
            </View>
          </View>
        }
        renderItem={(o) => (
          <ListItem
            title={o.name}
            subtitle={[o.lead_name, formatMoney(o.amount, o.currency, lang)].filter(Boolean).join(' · ')}
            meta={o.owner}
            right={<Badge label={o.stage_label} tone={stageTone[o.opportunity_stage] ?? 'neutral'} />}
            onPress={() => router.push(`/crm/opportunities/${o.id}`)}
          />
        )}
      />
    </>
  );
}
