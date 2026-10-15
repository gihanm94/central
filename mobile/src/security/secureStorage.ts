import * as SecureStore from 'expo-secure-store';

import type { TokenPair, TokenStore } from '@/api/tokens';
import { deserializePair, serializePair } from '@/api/tokens';

/**
 * Keychain (iOS) / Keystore-backed storage (Android). "This device only" and "when unlocked":
 * never in a backup, never readable while the phone is locked.
 */
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };

export const KEYS = {
  tokens: 'acme.tokens',
  installId: 'acme.install_id',
  settings: 'acme.settings',
  profile: 'acme.profile',
} as const;

export async function readSecure(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key, OPTIONS);
  } catch {
    return null; // e.g. the keychain item became unreadable after a restore: treat as absent
  }
}

export async function writeSecure(key: string, value: string): Promise<void> {
  await SecureStore.setItemAsync(key, value, OPTIONS);
}

export async function deleteSecure(key: string): Promise<void> {
  await SecureStore.deleteItemAsync(key, OPTIONS);
}

/** The API tokens live only here, never in AsyncStorage, files or logs. */
export const secureTokenStore: TokenStore = {
  async load(): Promise<TokenPair | null> {
    return deserializePair(await readSecure(KEYS.tokens));
  },
  async save(pair: TokenPair): Promise<void> {
    await writeSecure(KEYS.tokens, serializePair(pair));
  },
  async clear(): Promise<void> {
    await deleteSecure(KEYS.tokens);
  },
};
