import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { FlatList, Modal, Pressable, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useT } from '@/i18n';
import { useDebounced } from '@/lib/useDebounced';

import { SearchBox } from './InfiniteList';
import { colors, space } from './theme';
import { Button, EmptyState, ErrorView, ListItem, Loading, styles } from './ui';

export interface PickItem {
  id: number;
  label: string;
  sub?: string | null;
}

/** "Choose a lead / contact / opportunity" with server search. */
export function PickerField({
  label,
  value,
  onChange,
  load,
  queryKey,
  error,
  optional,
  disabled,
  testID,
}: {
  label: string;
  value: PickItem | null;
  onChange: (v: PickItem | null) => void;
  load: (q: string) => Promise<PickItem[]>;
  queryKey: unknown[];
  error?: string | null;
  optional?: boolean;
  disabled?: boolean;
  testID?: string;
}) {
  const t = useT();
  const [open, setOpen] = useState(false);
  const [text, setText] = useState('');
  const q = useDebounced(text.trim());
  const res = useQuery({ queryKey: [...queryKey, q], queryFn: () => load(q), enabled: open });

  return (
    <View style={styles.inputWrap}>
      <Text style={styles.inputLabel}>{label}</Text>
      <Pressable
        testID={testID}
        disabled={disabled}
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={label}
        style={[styles.input, !!error && styles.inputError, disabled && styles.disabled, { flexDirection: 'row', alignItems: 'center' }]}
      >
        <Text style={{ flex: 1, fontSize: 15, color: value ? colors.text : colors.faint }} numberOfLines={1}>
          {value ? value.label : t('common.choose')}
        </Text>
        {optional && value && (
          <Text onPress={() => onChange(null)} style={{ color: colors.primary, fontWeight: '700', paddingLeft: space.sm }} accessibilityRole="button">
            {t('common.clear')}
          </Text>
        )}
      </Pressable>
      {!!error && <Text style={styles.errorText}>{error}</Text>}
      <Modal visible={open} animationType="slide" onRequestClose={() => setOpen(false)}>
        <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', padding: space.md }}>
            <Text style={{ flex: 1, fontSize: 17, fontWeight: '700' }}>{label}</Text>
            <Button title={t('common.cancel')} variant="ghost" small onPress={() => setOpen(false)} />
          </View>
          <SearchBox value={text} onChange={setText} placeholder={t('common.search')} />
          <FlatList
            data={res.data ?? []}
            keyExtractor={(i) => String(i.id)}
            keyboardShouldPersistTaps="handled"
            renderItem={({ item }) => (
              <ListItem
                testID={`pick-${item.id}`}
                title={item.label}
                subtitle={item.sub}
                onPress={() => {
                  onChange(item);
                  setOpen(false);
                }}
              />
            )}
            ListEmptyComponent={res.isPending ? <Loading /> : res.isError ? <ErrorView error={res.error} onRetry={() => void res.refetch()} /> : <EmptyState title={t('common.noResults')} />}
          />
        </SafeAreaView>
      </Modal>
    </View>
  );
}
