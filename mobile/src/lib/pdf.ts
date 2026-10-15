import { File } from 'expo-file-system';
import * as Sharing from 'expo-sharing';

import type { Download } from '@/api/client';

import { pdfDirectory } from './files';

/** Stores a downloaded PDF in the app cache (wiped on sign-out). */
export function savePdf(download: Download, fallbackName: string): File {
  const name = (download.filename ?? fallbackName).replace(/[^A-Za-z0-9._-]/g, '_').replace(/^\.+/, '') || 'document.pdf';
  const file = new File(pdfDirectory(), name.toLowerCase().endsWith('.pdf') ? name : `${name}.pdf`);
  if (file.exists) file.delete();
  file.create();
  file.write(download.bytes);
  return file;
}

/** Opens the system sheet: preview, open in a PDF app, save or send. */
export async function openPdf(file: File, title: string): Promise<void> {
  if (!(await Sharing.isAvailableAsync())) throw new Error('sharing_unavailable');
  await Sharing.shareAsync(file.uri, { mimeType: 'application/pdf', UTI: 'com.adobe.pdf', dialogTitle: title });
}
