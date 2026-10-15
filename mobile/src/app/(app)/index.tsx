import { useQuery } from '@tanstack/react-query';
import { router, Stack } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import type { ModuleKey } from '@/api/types';
import { colors, font, radius, space } from '@/components/theme';
import { Badge, Button, Screen, Section } from '@/components/ui';
import { useT, type TKey } from '@/i18n';
import { can, hasModule } from '@/lib/permissions';
import { moduleHealthStore } from '@/state/moduleHealth';
import { api, refreshProfile, useSession } from '@/state/session';
import { useStore } from '@/lib/store';

const MODULES: { key: ModuleKey; title: TKey; text: TKey; href: string }[] = [
  { key: 'machines', title: 'machines.title', text: 'home.machinesText', href: '/machines' },
  { key: 'crm', title: 'crm.title', text: 'home.crmText', href: '/crm' },
  { key: 'accounting', title: 'accounting.title', text: 'home.accountingText', href: '/accounting' },
];

export default function Home() {
  const t = useT();
  const { profile } = useSession();
  const health = useStore(moduleHealthStore);
  const unread = useQuery({ queryKey: ['notifications', 'unread-count'], queryFn: () => api.me.notifications({ unread: true, per_page: 1 }) });
  const count = unread.data?.meta.total ?? 0;
  const visible = MODULES.filter((m) => hasModule(profile, m.key));

  return (
    <>
      <Stack.Screen
        options={{
          title: 'Acme',
          headerRight: () => (
            <Pressable onPress={() => router.push('/profile')} accessibilityRole="button" accessibilityLabel={t('profile.title')} hitSlop={10}>
              <Text style={styles.headerLink}>{t('profile.title')}</Text>
            </Pressable>
          ),
        }}
      />
      <Screen refreshing={false} onRefresh={() => void refreshProfile()}>
        <Text style={styles.hello}>{t('home.hello', { name: profile?.name ?? '' })}</Text>
        {count > 0 && (
          <Pressable onPress={() => router.push('/profile/notifications')} style={styles.notice} accessibilityRole="button">
            <Text style={{ color: colors.info, fontWeight: '600' }}>{t('home.unread', { n: count })}</Text>
          </Pressable>
        )}

        {(hasModule(profile, 'machines') || can(profile, 'crm', 'crm_activities', 'create')) && (
          <Section title={t('home.quick')}>
            <View style={styles.quick}>
              {hasModule(profile, 'machines') && (
                <Button title={t('machines.scan')} onPress={() => router.push('/machines/scan')} style={{ flex: 1 }} testID="quick-scan" />
              )}
              {can(profile, 'crm', 'crm_activities', 'create') && (
                <Button title={t('crm.logCall')} variant="secondary" onPress={() => router.push('/crm/activities/edit?type=CALL')} style={{ flex: 1 }} testID="quick-log-call" />
              )}
            </View>
          </Section>
        )}

        <Section title={t('home.modules')}>
          {visible.length === 0 && <Text style={styles.text}>{t('home.noModules')}</Text>}
          {visible.map((m) => {
            const down = (health[m.key] ?? 0) > Date.now();
            return (
              <Pressable
                key={m.key}
                testID={`module-${m.key}`}
                onPress={() => router.push(m.href as never)}
                accessibilityRole="button"
                style={({ pressed }) => [styles.tile, pressed && { opacity: 0.7 }]}
              >
                <View style={{ flex: 1 }}>
                  <Text style={styles.tileTitle}>{t(m.title)}</Text>
                  <Text style={styles.text}>{t(m.text)}</Text>
                </View>
                {down && <Badge label={t('errors.unavailableShort')} tone="warn" />}
              </Pressable>
            );
          })}
        </Section>
      </Screen>
    </>
  );
}

const styles = StyleSheet.create({
  headerLink: { color: colors.primary, fontWeight: '700', fontSize: font.body },
  hello: { fontSize: font.h2, fontWeight: '700', color: colors.text, marginBottom: space.md },
  notice: { backgroundColor: colors.infoSoft, padding: space.md, borderRadius: radius.md, marginBottom: space.md },
  quick: { flexDirection: 'row', gap: space.sm },
  tile: { flexDirection: 'row', alignItems: 'center', backgroundColor: colors.card, borderRadius: radius.lg, padding: space.lg, marginBottom: space.sm, borderWidth: 1, borderColor: colors.border },
  tileTitle: { fontSize: font.title, fontWeight: '700', color: colors.text },
  text: { fontSize: font.small, color: colors.muted, marginTop: 2 },
});
