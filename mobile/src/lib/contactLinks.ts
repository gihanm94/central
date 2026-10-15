import { Linking } from 'react-native';

/** tel: link from a stored phone number (digits, +, and an optional extension). */
export function telUrl(phone: string, ext?: string | null): string | null {
  const digits = phone.replace(/[^\d+]/g, '').replace(/(?!^)\+/g, '');
  if (digits.replace('+', '').length < 3) return null;
  const e = (ext ?? '').replace(/\D/g, '');
  return `tel:${digits}${e ? `,${e}` : ''}`;
}

export function mailtoUrl(email: string): string | null {
  const e = email.trim();
  return /^[^\s@<>()"',;:]+@[^\s@<>()"',;:]+\.[^\s@<>()"',;:]+$/.test(e) ? `mailto:${e}` : null;
}

/** Only tel: and mailto: links the app built itself. */
export function openContactLink(url: string | null): void {
  if (url && (url.startsWith('tel:') || url.startsWith('mailto:'))) void Linking.openURL(url).catch(() => undefined);
}
