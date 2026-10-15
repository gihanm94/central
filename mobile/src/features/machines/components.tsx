import { CameraView, useCameraPermissions } from 'expo-camera';
import { useRef, useState } from 'react';
import { Modal, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { colors, space } from '@/components/theme';
import { Badge, Button } from '@/components/ui';
import { useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { deleteQuietly } from '@/lib/files';
import { preparePhoto, type PreparedPhoto } from '@/lib/photo';

import { checkStatus, machineStatus } from './labels';

export function CheckStatusBadge({ status }: { status: string }) {
  const t = useT();
  const s = checkStatus(status);
  return <Badge label={s.key ? t(s.key) : status} tone={s.tone} />;
}

export function MachineStatusBadge({ status }: { status: string }) {
  const t = useT();
  const s = machineStatus(status);
  return <Badge label={s.key ? t(s.key) : status} tone={s.tone} />;
}

/** Full-screen camera that returns a JPEG under 2 MB. */
export function PhotoCapture({ visible, onClose, onPhoto }: { visible: boolean; onClose: () => void; onPhoto: (p: PreparedPhoto) => void }) {
  const t = useT();
  const [permission, requestPermission] = useCameraPermissions();
  const camera = useRef<CameraView>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function take() {
    if (!camera.current || busy) return;
    setBusy(true);
    setError(null);
    try {
      const shot = await camera.current.takePictureAsync({ quality: 0.8, exif: false });
      if (!shot) throw new Error('no_picture');
      const photo = await preparePhoto(shot.uri, shot.width);
      deleteQuietly(shot.uri);
      onPhoto(photo);
      onClose();
    } catch (e) {
      setError(errorMessage(e, t));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <SafeAreaView style={styles.wrap}>
        {!permission ? null : !permission.granted ? (
          <View style={styles.center}>
            <Text style={styles.text}>{t('camera.needed')}</Text>
            <Button title={t('camera.allow')} onPress={() => void requestPermission()} />
          </View>
        ) : (
          <CameraView ref={camera} style={{ flex: 1 }} facing="back" />
        )}
        {!!error && <Text style={styles.error}>{error}</Text>}
        <View style={styles.bar}>
          <Button title={t('common.cancel')} variant="secondary" onPress={onClose} style={{ flex: 1 }} />
          {permission?.granted && <Button title={t('camera.take')} onPress={() => void take()} loading={busy} style={{ flex: 1 }} testID="take-photo" />}
        </View>
      </SafeAreaView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#000' },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.md },
  text: { color: '#fff', textAlign: 'center', fontSize: 15 },
  error: { color: '#fecaca', textAlign: 'center', padding: space.sm },
  bar: { flexDirection: 'row', gap: space.sm, padding: space.md, backgroundColor: colors.text },
});
