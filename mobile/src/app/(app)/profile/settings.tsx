import { Stack } from 'expo-router';
import { useEffect, useState } from 'react';

import { Banner, Field, Screen, Section, Segmented, Toggle } from '@/components/ui';
import { useT } from '@/i18n';
import { appConfig } from '@/lib/appConfig';
import { deviceIsSecure } from '@/security/AppLock';
import { effectiveServerUrl, LOCK_CHOICES, updateSettings, useSettings, type LanguageSetting } from '@/state/settings';

export default function SettingsScreen() {
  const t = useT();
  const s = useSettings();
  const [secure, setSecure] = useState<boolean | null>(null);
  useEffect(() => {
    void deviceIsSecure().then(setSecure);
  }, []);

  return (
    <>
      <Stack.Screen options={{ title: t('profile.settings') }} />
      <Screen>
        <Section title={t('settings.language')}>
          <Segmented<LanguageSetting>
            testIDPrefix="lang"
            options={[
              { value: 'system', label: t('settings.languageSystem') },
              { value: 'en', label: 'English' },
              { value: 'th', label: 'ไทย' },
            ]}
            value={s.language}
            onChange={(language) => void updateSettings({ language })}
          />
        </Section>
        <Section title={t('settings.security')}>
          <Toggle label={t('settings.appLock')} hint={t('settings.appLockHint')} value={s.appLock} onChange={(appLock) => void updateSettings({ appLock })} />
          {s.appLock && secure === false && <Banner tone="warn" text={t('settings.noPasscode')} />}
          {s.appLock && (
            <Segmented
              label={t('settings.lockAfter')}
              options={LOCK_CHOICES.map((n) => ({ value: n, label: n === 0 ? t('settings.immediately') : t('settings.minutes', { n }) }))}
              value={s.lockAfterMinutes}
              onChange={(lockAfterMinutes) => void updateSettings({ lockAfterMinutes })}
            />
          )}
        </Section>
        <Section title={t('server.title')}>
          <Field label={t('server.label')} value={effectiveServerUrl(s)} />
          <Field label={t('settings.version')} value={`${appConfig.version} (${appConfig.variant})`} />
        </Section>
      </Screen>
    </>
  );
}
