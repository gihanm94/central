import { CameraView, useCameraPermissions, type BarcodeScanningResult } from 'expo-camera';
import { router, Stack, useFocusEffect } from 'expo-router';
import { useCallback, useRef, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';

import { colors, space } from '@/components/theme';
import { Button } from '@/components/ui';
import { useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { checkScannedText } from '@/lib/qr';
import { api, queryClient } from '@/state/session';

/**
 * QR scan. The text goes to POST /machines/scan as it is (JSON QR, link or code): the app never
 * opens a URL from a code, and the server only takes a machine code out of it.
 */
export default function ScanScreen() {
  const t = useT();
  const [permission, requestPermission] = useCameraPermissions();
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [active, setActive] = useState(true);
  const handling = useRef(false);
  const lastText = useRef<{ text: string; at: number } | null>(null);

  useFocusEffect(
    useCallback(() => {
      setActive(true);
      handling.current = false;
      return () => setActive(false);
    }, []),
  );

  async function onScanned(result: BarcodeScanningResult) {
    if (handling.current) return;
    const check = checkScannedText(result.data);
    const now = Date.now();
    // the camera reports the same code many times a second
    if (lastText.current && lastText.current.text === result.data && now - lastText.current.at < 2500) return;
    lastText.current = { text: result.data, at: now };
    if (!check.ok) {
      setMessage(t(`machines.scanError.${check.reason}`));
      return;
    }
    handling.current = true;
    setBusy(true);
    setMessage(null);
    try {
      const screen = (await api.machines.scan(check.text)).data;
      const code = screen.machine.machine_code;
      queryClient.setQueryData(['machines', 'machine', code], { data: screen });
      router.replace(`/machines/m/${encodeURIComponent(code)}`);
    } catch (e) {
      setMessage(errorMessage(e, t));
      handling.current = false;
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Stack.Screen options={{ title: t('machines.scan') }} />
      <View style={styles.wrap}>
        {!permission ? null : !permission.granted ? (
          <View style={styles.center}>
            <Text style={styles.text}>{t('camera.needed')}</Text>
            <Button title={t('camera.allow')} onPress={() => void requestPermission()} />
          </View>
        ) : (
          active && (
            <CameraView
              style={StyleSheet.absoluteFill}
              facing="back"
              barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
              onBarcodeScanned={busy ? undefined : (r) => void onScanned(r)}
            />
          )
        )}
        <View style={styles.frame} pointerEvents="none" />
        <View style={styles.panel}>
          <Text style={styles.text}>{busy ? t('machines.looking') : (message ?? t('machines.scanHint'))}</Text>
          <Button title={t('machines.typeCode')} variant="secondary" onPress={() => router.replace('/machines/search')} />
        </View>
      </View>
    </>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#000' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.md },
  text: { color: '#fff', textAlign: 'center', fontSize: 15 },
  frame: { position: 'absolute', top: '22%', left: '15%', width: '70%', aspectRatio: 1, borderWidth: 3, borderColor: colors.primarySoft, borderRadius: 16 },
  panel: { position: 'absolute', left: 0, right: 0, bottom: 0, padding: space.lg, gap: space.md, backgroundColor: 'rgba(0,0,0,0.65)' },
});
