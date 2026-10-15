import * as Crypto from 'expo-crypto';

import { KEYS, readSecure, writeSecure } from './secureStorage';

let cached: string | null = null;

/**
 * A random id made once per install and kept in secure storage. Sent as device_id at sign-in,
 * so signing in again on this install replaces its old sign-in on the server.
 */
export async function getInstallId(): Promise<string> {
  if (cached) return cached;
  const stored = await readSecure(KEYS.installId);
  if (stored && /^[A-Za-z0-9._-]{8,120}$/.test(stored)) {
    cached = stored;
    return stored;
  }
  const id = `i-${Crypto.randomUUID()}`;
  await writeSecure(KEYS.installId, id);
  cached = id;
  return id;
}
