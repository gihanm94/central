import { Stack } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { InfiniteList } from '@/components/InfiniteList';
import { Badge, ListItem, Segmented } from '@/components/ui';
import { useLanguage, useT } from '@/i18n';
import { formatDateTime, htmlToText } from '@/lib/format';
import { api } from '@/state/session';

export default function NotificationsScreen() {
  const t = useT();
  const lang = useLanguage();
  const [unread, setUnread] = useState<'all' | 'unread'>('all');
  return (
    <>
      <Stack.Screen options={{ title: t('profile.notifications') }} />
      <InfiniteList
        queryKey={['notifications', { unread }]}
        fetchPage={(page) => api.me.notifications({ unread: unread === 'unread', page })}
        keyOf={(n) => n.id}
        emptyTitle={t('notifications.none')}
        header={
          <View style={{ padding: 12 }}>
            <Segmented options={[{ value: 'all', label: t('crm.all') }, { value: 'unread', label: t('notifications.unread') }]} value={unread} onChange={setUnread} />
          </View>
        }
        renderItem={(n) => (
          <ListItem
            title={n.title}
            subtitle={htmlToText(n.body)}
            meta={formatDateTime(n.created_at, lang)}
            right={n.read_at ? undefined : <Badge label={t('notifications.new')} tone="info" />}
          />
        )}
      />
    </>
  );
}
