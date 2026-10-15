import { useState } from 'react';
import { Text, View } from 'react-native';

import type { Download } from '@/api/client';
import { colors } from '@/components/theme';
import { Button } from '@/components/ui';
import { useT } from '@/i18n';
import { errorMessage } from '@/lib/errorMessage';
import { openPdf, savePdf } from '@/lib/pdf';

/**
 * Downloads a stored PDF with the Authorization header and opens the system sheet
 * (preview, open in a PDF app, share). A PDF that was never made shows the server's
 * 404 pdf_not_available message. Nothing is ever generated or sent from the app.
 */
export function PdfButton({ title, fileName, download, testID }: { title: string; fileName: string; download: () => Promise<Download>; testID?: string }) {
  const t = useT();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    setBusy(true);
    setError(null);
    try {
      const file = savePdf(await download(), fileName);
      await openPdf(file, title);
    } catch (e) {
      setError(e instanceof Error && e.message === 'sharing_unavailable' ? t('accounting.cannotOpen') : errorMessage(e, t));
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={{ marginTop: 8 }}>
      <Button title={title} variant="secondary" small onPress={() => void run()} loading={busy} testID={testID} />
      {!!error && (
        <Text style={{ color: colors.danger, fontSize: 13, marginTop: 4 }} testID={testID ? `${testID}-error` : undefined}>
          {error}
        </Text>
      )}
    </View>
  );
}
