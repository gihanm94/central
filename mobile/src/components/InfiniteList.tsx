import { useInfiniteQuery, type QueryKey } from '@tanstack/react-query';
import type { ReactElement, ReactNode } from 'react';
import { ActivityIndicator, FlatList, RefreshControl, StyleSheet, TextInput, View } from 'react-native';

import type { Page } from '@/api/types';
import { useT } from '@/i18n';
import { noteInteraction } from '@/security/activity';

import { colors, radius, space } from './theme';
import { EmptyState, ErrorView } from './ui';

/** A paged list ({data, meta}) that loads the next page at the end and pulls to refresh. */
export function InfiniteList<T>({
  queryKey,
  fetchPage,
  renderItem,
  keyOf,
  header,
  emptyTitle,
  emptyMessage,
  enabled = true,
}: {
  queryKey: QueryKey;
  fetchPage: (page: number) => Promise<Page<T>>;
  renderItem: (item: T) => ReactElement;
  keyOf: (item: T) => string | number;
  header?: ReactNode;
  emptyTitle?: string;
  emptyMessage?: string;
  enabled?: boolean;
}) {
  const t = useT();
  const q = useInfiniteQuery({
    queryKey,
    queryFn: ({ pageParam }) => fetchPage(pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.page < last.meta.pages ? last.meta.page + 1 : undefined),
    enabled,
  });
  const items = q.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <FlatList
      style={styles.list}
      data={items}
      keyExtractor={(item) => String(keyOf(item))}
      renderItem={({ item }) => renderItem(item)}
      keyboardShouldPersistTaps="handled"
      ListHeaderComponent={header ? <View>{header}</View> : null}
      ListEmptyComponent={
        q.isPending && enabled ? (
          <ActivityIndicator style={{ margin: space.xl }} color={colors.primary} />
        ) : q.isError ? (
          <ErrorView error={q.error} onRetry={() => void q.refetch()} />
        ) : (
          <EmptyState title={emptyTitle ?? t('common.nothing')} message={emptyMessage} />
        )
      }
      ListFooterComponent={q.isFetchingNextPage ? <ActivityIndicator style={{ margin: space.lg }} color={colors.primary} /> : null}
      onEndReachedThreshold={0.4}
      onEndReached={() => {
        if (q.hasNextPage && !q.isFetchingNextPage && !q.isError) void q.fetchNextPage();
      }}
      refreshControl={<RefreshControl refreshing={q.isRefetching && !q.isFetchingNextPage} onRefresh={() => void q.refetch()} />}
    />
  );
}

export function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <View style={styles.searchWrap}>
      <TextInput
        value={value}
        onChangeText={(v) => {
          noteInteraction();
          onChange(v);
        }}
        placeholder={placeholder}
        placeholderTextColor={colors.faint}
        style={styles.search}
        autoCorrect={false}
        autoCapitalize="none"
        clearButtonMode="while-editing"
        returnKeyType="search"
        accessibilityLabel={placeholder}
        maxLength={100}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: colors.bg },
  searchWrap: { padding: space.md, backgroundColor: colors.bg },
  search: {
    backgroundColor: colors.card,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: space.md,
    paddingVertical: 10,
    fontSize: 15,
    color: colors.text,
  },
});
