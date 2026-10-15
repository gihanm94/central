/**
 * The server address people type ("erp.example.com", "https://erp.example.com/",
 * "https://erp.example.com/api/v1") → the API root "https://erp.example.com/api/v1".
 *
 * HTTPS only. A development build may use plain http:// for the local machine
 * (localhost, 127.0.0.1, ::1, 10.0.2.2 = the Android emulator's view of the host).
 *
 * Parsed by hand: React Native's URL class does not implement every getter.
 */
export type ServerUrlError = 'empty' | 'invalid' | 'https_required';

export type ServerUrlResult = { ok: true; url: string; origin: string } | { ok: false; error: ServerUrlError };

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]', '10.0.2.2']);

export function apiBaseFromServerUrl(input: string, opts: { allowInsecureLocal: boolean }): ServerUrlResult {
  let text = input.trim();
  if (!text) return { ok: false, error: 'empty' };
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(text)) text = `https://${text}`;

  const m = /^([a-z][a-z0-9+.-]*):\/\/([^/?#]*)([^?#]*)(\?[^#]*)?(#.*)?$/i.exec(text);
  if (!m) return { ok: false, error: 'invalid' };
  const scheme = m[1]!.toLowerCase();
  const authority = m[2]!;
  let path = m[3] ?? '';
  if (m[4] || m[5]) return { ok: false, error: 'invalid' }; // no query or fragment
  if (authority.includes('@')) return { ok: false, error: 'invalid' }; // no user:password@

  const hm = /^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(\d{1,5}))?$/i.exec(authority);
  if (!hm) return { ok: false, error: 'invalid' };
  const host = hm[1]!.toLowerCase();
  const port = hm[2];
  if (port !== undefined && (Number(port) < 1 || Number(port) > 65535)) return { ok: false, error: 'invalid' };
  if (!host.startsWith('[') && (host.startsWith('.') || host.endsWith('.') || host.includes('..') || host.startsWith('-'))) {
    return { ok: false, error: 'invalid' };
  }

  if (scheme === 'http') {
    if (!opts.allowInsecureLocal || !LOCAL_HOSTS.has(host)) return { ok: false, error: 'https_required' };
  } else if (scheme !== 'https') {
    return { ok: false, error: 'invalid' };
  }
  if (/[^A-Za-z0-9._~!$&'()*+,;=:@%/-]/.test(path) || path.includes('/../') || path.endsWith('/..')) {
    return { ok: false, error: 'invalid' };
  }

  path = path.replace(/\/+$/, '');
  if (!/\/api\/v1$/.test(path)) path = `${path}/api/v1`;
  const origin = `${scheme}://${host}${port ? `:${port}` : ''}`;
  return { ok: true, url: `${origin}${path}`, origin: `${origin}${path.replace(/\/api\/v1$/, '')}` };
}
