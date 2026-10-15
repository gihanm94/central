import { router } from 'expo-router';
import { useState } from 'react';
import { Text } from 'react-native';

import { Banner, Button, Screen, TextField, styles } from '@/components/ui';
import { useT } from '@/i18n';
import { appConfig } from '@/lib/appConfig';
import { apiBaseFromServerUrl } from '@/lib/serverUrl';
import { useSession } from '@/state/session';
import { updateSettings, useSettings } from '@/state/settings';

/** Server address. Changeable only while signed out (tokens belong to one server). */
export default function ServerScreen() {
  const t = useT();
  const settings = useSettings();
  const { status } = useSession();
  const [value, setValue] = useState(settings.serverUrl || appConfig.defaultServerUrl);
  const [error, setError] = useState<string | null>(null);
  const signedIn = status === 'signedIn';

  async function save() {
    const r = apiBaseFromServerUrl(value, { allowInsecureLocal: appConfig.allowInsecureLocal });
    if (!r.ok) {
      setError(t(`server.error.${r.error}`));
      return;
    }
    await updateSettings({ serverUrl: r.origin === appConfig.defaultServerUrl.replace(/\/+$/, '') ? '' : r.origin });
    router.back();
  }

  return (
    <Screen keyboard>
      {signedIn && <Banner tone="warn" text={t('server.signOutFirst')} />}
      <TextField
        label={t('server.label')}
        value={value}
        onChangeText={(v) => {
          setValue(v);
          setError(null);
        }}
        placeholder="https://erp.example.com"
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        editable={!signedIn}
        error={error}
        hint={appConfig.allowInsecureLocal ? t('server.hintDev') : t('server.hint')}
        testID="server-url"
      />
      {!!appConfig.defaultServerUrl && <Text style={styles.hint}>{t('server.default', { url: appConfig.defaultServerUrl })}</Text>}
      <Button title={t('common.save')} onPress={() => void save()} disabled={signedIn} style={{ marginTop: 16 }} testID="server-save" />
    </Screen>
  );
}
