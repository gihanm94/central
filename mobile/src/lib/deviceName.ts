/** "<name>'s <model>", e.g. "Somchai's iPhone 15". The name comes from the e-mail before sign-in. */
export function defaultDeviceName(email: string, model: string | null | undefined): string {
  const phone = (model ?? '').trim() || 'phone';
  const local = email.trim().split('@')[0] ?? '';
  const first = local.split(/[._+-]/)[0] ?? '';
  if (!/^[A-Za-z][A-Za-z0-9]{0,30}$/.test(first)) return phone;
  return `${first.charAt(0).toUpperCase()}${first.slice(1).toLowerCase()}'s ${phone}`.slice(0, 120);
}

export function apiPlatform(os: string): 'ios' | 'android' | 'other' {
  return os === 'ios' || os === 'android' ? os : 'other';
}
