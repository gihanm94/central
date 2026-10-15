import * as Device from 'expo-device';
import { Link } from 'expo-router';
import { useEffect, useState } from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { isApiError } from '@/api/errors';
import { colors, font, space } from '@/components/theme';
import { Banner, Button, Screen, TextField } from '@/components/ui';
import { useT } from '@/i18n';
import { apiPlatform, defaultDeviceName } from '@/lib/deviceName';
import { errorMessage } from '@/lib/errorMessage';
import { formatSeconds, useCountdown } from '@/lib/useCountdown';
import { getInstallId } from '@/security/installId';
import { clearNotice, signIn, useSession } from '@/state/session';
import { currentApiBase, effectiveServerUrl, useSettings } from '@/state/settings';

export default function SignInScreen() {
  const t = useT();
  const { notice } = useSession();
  const settings = useSettings();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [deviceName, setDeviceName] = useState(() => defaultDeviceName('', Device.modelName));
  const [nameTouched, setNameTouched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [lockedUntil, setLockedUntil] = useState<number | null>(null);
  const lockedFor = useCountdown(lockedUntil);

  useEffect(() => {
    if (!nameTouched) setDeviceName(defaultDeviceName(email, Device.modelName));
  }, [email, nameTouched]);

  const server = effectiveServerUrl(settings);
  const serverOk = !!currentApiBase();

  async function submit() {
    setError(null);
    setFields({});
    const local: Record<string, string> = {};
    if (!email.trim()) local.email = t('signIn.emailRequired');
    if (!password) local.password = t('signIn.passwordRequired');
    if (!deviceName.trim()) local.device_name = t('signIn.deviceRequired');
    if (Object.keys(local).length) {
      setFields(local);
      return;
    }
    setBusy(true);
    clearNotice();
    try {
      await signIn({
        email: email.trim(),
        password,
        device_name: deviceName.trim().slice(0, 120),
        device_id: await getInstallId(),
        platform: apiPlatform(Platform.OS),
      });
      setPassword('');
    } catch (e) {
      setPassword('');
      if (isApiError(e) && e.code === 'locked') {
        setLockedUntil(Date.now() + (e.retryAfter ?? 60) * 1000);
      }
      if (isApiError(e) && e.isValidation && e.fields) setFields(e.fields);
      setError(errorMessage(e, t));
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}>
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <Screen keyboard>
          <View style={styles.head}>
            <Text style={styles.brand}>Acme</Text>
            <Text style={styles.sub}>{t('signIn.subtitle')}</Text>
          </View>

          {!!notice && <Banner text={notice} tone="warn" testID="sign-in-notice" />}
          {!!error && lockedFor === 0 && <Banner text={error} tone="danger" testID="sign-in-error" />}
          {lockedFor > 0 && <Banner text={`${error ?? ''}\n${t('signIn.lockedFor', { time: formatSeconds(lockedFor) })}`} tone="danger" testID="sign-in-locked" />}

          <View style={styles.server}>
            <View style={{ flex: 1 }}>
              <Text style={styles.serverLabel}>{t('server.label')}</Text>
              <Text style={[styles.serverValue, !serverOk && { color: colors.danger }]} numberOfLines={1}>
                {server || t('server.notSet')}
              </Text>
            </View>
            <Link href="/server" style={styles.change}>
              {t('common.change')}
            </Link>
          </View>

          <TextField
            label={t('signIn.email')}
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            textContentType="username"
            autoComplete="email"
            error={fields.email}
            testID="email"
          />
          <TextField
            label={t('signIn.password')}
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            textContentType="password"
            autoComplete="password"
            error={fields.password}
            testID="password"
            onSubmitEditing={() => void submit()}
          />
          <TextField
            label={t('signIn.deviceName')}
            value={deviceName}
            onChangeText={(v) => {
              setNameTouched(true);
              setDeviceName(v);
            }}
            maxLength={120}
            hint={t('signIn.deviceHint')}
            error={fields.device_name}
            testID="device-name"
          />
          <Button
            title={t('signIn.submit')}
            onPress={() => void submit()}
            loading={busy}
            disabled={!serverOk || lockedFor > 0}
            testID="sign-in"
          />
        </Screen>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  head: { alignItems: 'center', marginTop: space.xl, marginBottom: space.xl },
  brand: { fontSize: 34, fontWeight: '800', color: colors.primary },
  sub: { fontSize: font.body, color: colors.muted, marginTop: space.xs },
  server: { flexDirection: 'row', alignItems: 'center', backgroundColor: colors.card, borderRadius: 10, padding: space.md, marginBottom: space.lg, borderWidth: 1, borderColor: colors.border },
  serverLabel: { fontSize: 12, color: colors.muted },
  serverValue: { fontSize: font.body, color: colors.text },
  change: { color: colors.primary, fontWeight: '700', padding: space.sm },
});
