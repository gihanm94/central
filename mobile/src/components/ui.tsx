import type { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  TextInput,
  View,
  type StyleProp,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';

import { isApiError } from '@/api/errors';
import { useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { noteInteraction } from '@/security/activity';

import { colors, font, radius, space, tones, type Tone } from './theme';

/* ------------------------------------------------------------------ layout */

export function Screen({
  children,
  refreshing,
  onRefresh,
  padded = true,
  keyboard = false,
}: {
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  padded?: boolean;
  keyboard?: boolean;
}) {
  return (
    <ScrollView
      style={styles.screen}
      contentContainerStyle={[padded && styles.screenPad]}
      keyboardShouldPersistTaps={keyboard ? 'handled' : 'never'}
      automaticallyAdjustKeyboardInsets
      refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} /> : undefined}
    >
      {children}
    </ScrollView>
  );
}

export function Section({ title, right, children, style }: { title?: string; right?: ReactNode; children: ReactNode; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[styles.section, style]}>
      {(title || right) && (
        <View style={styles.sectionHead}>
          {title ? <Text style={styles.sectionTitle}>{title}</Text> : <View />}
          {right}
        </View>
      )}
      {children}
    </View>
  );
}

export function Card({ children, onPress, style, accessibilityLabel }: { children: ReactNode; onPress?: () => void; style?: StyleProp<ViewStyle>; accessibilityLabel?: string }) {
  if (onPress) {
    return (
      <Pressable
        onPress={onPress}
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        style={({ pressed }) => [styles.card, pressed && styles.pressed, style]}
      >
        {children}
      </Pressable>
    );
  }
  return <View style={[styles.card, style]}>{children}</View>;
}

/** A tappable row in a list. */
export function ListItem({
  title,
  subtitle,
  meta,
  right,
  onPress,
  testID,
}: {
  title: string;
  subtitle?: string | null;
  meta?: string | null;
  right?: ReactNode;
  onPress?: () => void;
  testID?: string;
}) {
  const body = (
    <>
      <View style={styles.itemBody}>
        <Text style={styles.itemTitle} numberOfLines={2}>
          {title}
        </Text>
        {!!subtitle && (
          <Text style={styles.itemSub} numberOfLines={2}>
            {subtitle}
          </Text>
        )}
        {!!meta && (
          <Text style={styles.itemMeta} numberOfLines={1}>
            {meta}
          </Text>
        )}
      </View>
      {right}
      {onPress && <Text style={styles.chevron}>›</Text>}
    </>
  );
  return onPress ? (
    <Pressable testID={testID} onPress={onPress} accessibilityRole="button" style={({ pressed }) => [styles.item, pressed && styles.pressed]}>
      {body}
    </Pressable>
  ) : (
    <View testID={testID} style={styles.item}>
      {body}
    </View>
  );
}

/** Label / value line on detail screens. Values are selectable; an onPress makes it a link. */
export function Field({ label, value, onPress }: { label: string; value?: string | number | null; onPress?: () => void }) {
  const text = value === null || value === undefined || value === '' ? '—' : String(value);
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      {onPress && text !== '—' ? (
        <Text style={[styles.fieldValue, styles.link]} onPress={onPress} accessibilityRole="link">
          {text}
        </Text>
      ) : (
        <Text style={styles.fieldValue} selectable>
          {text}
        </Text>
      )}
    </View>
  );
}

export function Title({ children, sub }: { children: ReactNode; sub?: string | null }) {
  return (
    <View style={{ marginBottom: space.md }}>
      <Text style={styles.h1}>{children}</Text>
      {!!sub && <Text style={styles.itemSub}>{sub}</Text>}
    </View>
  );
}

/* ----------------------------------------------------------------- controls */

export function Button({
  title,
  onPress,
  variant = 'primary',
  loading,
  disabled,
  style,
  testID,
  small,
}: {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
  loading?: boolean;
  disabled?: boolean;
  style?: StyleProp<ViewStyle>;
  testID?: string;
  small?: boolean;
}) {
  const v = buttonVariants[variant];
  const off = disabled || loading;
  return (
    <Pressable
      testID={testID}
      onPress={onPress}
      disabled={off}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!off, busy: !!loading }}
      style={({ pressed }) => [styles.button, small && styles.buttonSmall, { backgroundColor: v.bg, borderColor: v.border }, pressed && styles.pressed, off && styles.disabled, style]}
    >
      {loading ? <ActivityIndicator color={v.fg} /> : <Text style={[styles.buttonText, small && { fontSize: font.small }, { color: v.fg }]}>{title}</Text>}
    </Pressable>
  );
}

const buttonVariants = {
  primary: { bg: colors.primary, fg: colors.primaryText, border: colors.primary },
  secondary: { bg: colors.card, fg: colors.text, border: colors.border },
  danger: { bg: colors.card, fg: colors.danger, border: colors.danger },
  ghost: { bg: 'transparent', fg: colors.primary, border: 'transparent' },
};

export function TextField({ label, error, hint, style, ...input }: TextInputProps & { label: string; error?: string | null; hint?: string | null }) {
  return (
    <View style={[styles.inputWrap, style as StyleProp<ViewStyle>]}>
      <Text style={styles.inputLabel}>{label}</Text>
      <TextInput
        placeholderTextColor={colors.faint}
        accessibilityLabel={label}
        style={[styles.input, input.multiline && styles.inputMulti, !!error && styles.inputError]}
        {...input}
        onChangeText={(v) => {
          noteInteraction(); // typing keeps the idle lock away
          input.onChangeText?.(v);
        }}
      />
      {!!error && <Text style={styles.errorText}>{error}</Text>}
      {!error && !!hint && <Text style={styles.hint}>{hint}</Text>}
    </View>
  );
}

export interface Option<V extends string | number> {
  value: V;
  label: string;
  tone?: Tone;
}

/** Row of choice chips (single select). */
export function Segmented<V extends string | number>({
  options,
  value,
  onChange,
  label,
  error,
  testIDPrefix,
}: {
  options: readonly Option<V>[];
  value: V | null | undefined;
  onChange: (v: V) => void;
  label?: string;
  error?: string | null;
  testIDPrefix?: string;
}) {
  return (
    <View style={styles.inputWrap}>
      {!!label && <Text style={styles.inputLabel}>{label}</Text>}
      <View style={styles.chips} accessibilityRole="radiogroup">
        {options.map((o) => {
          const on = o.value === value;
          const tone = tones[o.tone ?? 'primary'];
          return (
            <Pressable
              key={String(o.value)}
              testID={testIDPrefix ? `${testIDPrefix}-${o.value}` : undefined}
              onPress={() => onChange(o.value)}
              accessibilityRole="radio"
              accessibilityState={{ selected: on }}
              accessibilityLabel={o.label}
              style={[styles.chip, on && { backgroundColor: tone.bg, borderColor: tone.fg }]}
            >
              <Text style={[styles.chipText, on && { color: tone.fg, fontWeight: '700' }]}>{o.label}</Text>
            </Pressable>
          );
        })}
      </View>
      {!!error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

export function Toggle({ label, value, onChange, hint }: { label: string; value: boolean; onChange: (v: boolean) => void; hint?: string }) {
  return (
    <View style={styles.toggle}>
      <View style={{ flex: 1 }}>
        <Text style={styles.itemTitle}>{label}</Text>
        {!!hint && <Text style={styles.hint}>{hint}</Text>}
      </View>
      <Switch value={value} onValueChange={onChange} accessibilityLabel={label} />
    </View>
  );
}

export function Badge({ label, tone = 'neutral' }: { label: string; tone?: Tone }) {
  const c = tones[tone];
  return (
    <View style={[styles.badge, { backgroundColor: c.bg }]}>
      <Text style={[styles.badgeText, { color: c.fg }]}>{label}</Text>
    </View>
  );
}

export function Stat({ label, value, tone, onPress }: { label: string; value: string | number; tone?: Tone; onPress?: () => void }) {
  return (
    <Card onPress={onPress} style={styles.stat}>
      <Text style={[styles.statValue, tone && { color: tones[tone].fg }]}>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
    </Card>
  );
}

export function StatGrid({ children }: { children: ReactNode }) {
  return <View style={styles.statGrid}>{children}</View>;
}

/* ------------------------------------------------------------------- states */

export function Loading({ label }: { label?: string }) {
  return (
    <View style={styles.center} accessibilityLabel={label} accessibilityRole="progressbar">
      <ActivityIndicator size="large" color={colors.primary} />
    </View>
  );
}

export function EmptyState({ title, message }: { title: string; message?: string }) {
  return (
    <View style={styles.empty}>
      <Text style={styles.emptyTitle}>{title}</Text>
      {!!message && <Text style={styles.emptyText}>{message}</Text>}
    </View>
  );
}

export function Banner({ text, tone = 'info', testID }: { text: string; tone?: Tone; testID?: string }) {
  const c = tones[tone];
  return (
    <View testID={testID} style={[styles.banner, { backgroundColor: c.bg }]} accessibilityRole="alert">
      <Text style={{ color: c.fg, fontSize: font.body }}>{text}</Text>
    </View>
  );
}

/** Any error: the server's message, "temporarily unavailable" for a module, "no connection" … */
export function ErrorView({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const t = useT();
  const unavailable = isApiError(error) && error.code === 'module_unavailable';
  return (
    <View style={styles.empty} testID="error-view">
      <Text style={styles.emptyTitle}>{unavailable ? t('errors.module_unavailable_title') : t('errors.title')}</Text>
      <Text style={styles.emptyText}>{errorMessage(error, t)}</Text>
      {isApiError(error) && error.requestId && error.status >= 500 && <Text style={styles.hint}>{t('errors.reference', { ref: error.requestId })}</Text>}
      {onRetry && <Button title={t('common.retry')} onPress={onRetry} variant="secondary" style={{ marginTop: space.md, alignSelf: 'center' }} />}
    </View>
  );
}

export const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  screenPad: { padding: space.lg, paddingBottom: space.xl * 2 },
  section: { marginBottom: space.lg },
  sectionHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: space.sm },
  sectionTitle: { fontSize: font.small, fontWeight: '700', color: colors.muted, textTransform: 'uppercase', letterSpacing: 0.5 },
  card: { backgroundColor: colors.card, borderRadius: radius.lg, padding: space.lg, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.border },
  pressed: { opacity: 0.7 },
  disabled: { opacity: 0.5 },
  item: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: colors.card,
    paddingHorizontal: space.lg,
    paddingVertical: space.md,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
    gap: space.sm,
  },
  itemBody: { flex: 1, gap: 2 },
  itemTitle: { fontSize: font.body, fontWeight: '600', color: colors.text },
  itemSub: { fontSize: font.small, color: colors.muted },
  itemMeta: { fontSize: 12, color: colors.faint },
  chevron: { fontSize: 22, color: colors.faint, marginLeft: space.xs },
  field: { paddingVertical: space.sm, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  fieldLabel: { fontSize: 12, color: colors.muted, marginBottom: 2 },
  fieldValue: { fontSize: font.body, color: colors.text },
  link: { color: colors.info, textDecorationLine: 'underline' },
  h1: { fontSize: font.h2, fontWeight: '700', color: colors.text },
  button: { minHeight: 46, borderRadius: radius.md, borderWidth: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: space.lg },
  buttonSmall: { minHeight: 34, paddingHorizontal: space.md },
  buttonText: { fontSize: font.body, fontWeight: '700' },
  inputWrap: { marginBottom: space.md },
  inputLabel: { fontSize: font.small, fontWeight: '600', color: colors.text, marginBottom: space.xs },
  input: {
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.md,
    paddingHorizontal: space.md,
    paddingVertical: 10,
    fontSize: font.body,
    color: colors.text,
  },
  inputMulti: { minHeight: 90, textAlignVertical: 'top' },
  inputError: { borderColor: colors.danger },
  errorText: { color: colors.danger, fontSize: font.small, marginTop: space.xs },
  hint: { color: colors.muted, fontSize: 12, marginTop: space.xs },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  chip: { borderWidth: 1, borderColor: colors.border, backgroundColor: colors.card, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 8, minHeight: 38, justifyContent: 'center' },
  chipText: { fontSize: font.small, color: colors.text },
  toggle: { flexDirection: 'row', alignItems: 'center', paddingVertical: space.sm, gap: space.md },
  badge: { borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2, alignSelf: 'flex-start' },
  badgeText: { fontSize: 12, fontWeight: '700' },
  stat: { flexBasis: '47%', flexGrow: 1, padding: space.md },
  statValue: { fontSize: font.h2, fontWeight: '800', color: colors.text },
  statLabel: { fontSize: 12, color: colors.muted, marginTop: 2 },
  statGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, minHeight: 160 },
  empty: { alignItems: 'center', padding: space.xl, gap: space.xs },
  emptyTitle: { fontSize: font.title, fontWeight: '700', color: colors.text, textAlign: 'center' },
  emptyText: { fontSize: font.body, color: colors.muted, textAlign: 'center' },
  banner: { borderRadius: radius.md, padding: space.md, marginBottom: space.md },
});
