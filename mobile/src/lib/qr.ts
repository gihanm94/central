import { MAX_SCAN_LENGTH } from '@/api/endpoints';

export type ScanCheck = { ok: true; text: string } | { ok: false; reason: 'empty' | 'too_long' | 'binary' };

/**
 * What the camera read, checked before it goes to POST /machines/scan.
 * The app never opens a URL from a QR code: links are only passed to the server, which takes
 * the machine code out of a /machines/m/{code} link and ignores anything else.
 */
export function checkScannedText(raw: string | null | undefined): ScanCheck {
  const text = (raw ?? '').trim();
  if (!text) return { ok: false, reason: 'empty' };
  if (text.length > MAX_SCAN_LENGTH) return { ok: false, reason: 'too_long' };
  // eslint-disable-next-line no-control-regex
  if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(text)) return { ok: false, reason: 'binary' };
  return { ok: true, text };
}

/** A machine code typed by hand (same characters the server accepts for a bare code). */
export function looksLikeMachineCode(text: string): boolean {
  return /^[A-Za-z0-9._-]{3,40}$/.test(text.trim());
}
